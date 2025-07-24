<?php

namespace App\Controller;

use App\Entity\Lists;
use App\Entity\CreatedShops;
use App\Entity\User;
use App\Entity\HouseholdUsers;
use App\Entity\Household;
use App\Service\LocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * Controller for the main application functionality
 * Handles shopping lists, product management, and shop-specific lists
 */
class ApplicationController extends AbstractController
{
    /**
     * Display the main application page with shopping lists grouped by shops
     * GET /application - Shows all shopping lists organized by shop
     */
    #[Route('/application', name: 'application')]
    public function index(EntityManagerInterface $em, Security $security, SessionInterface $session, LoggerInterface $logger)
    {
        $logger->info('Application page accessed');

        // Get authenticated user
        $user = $security->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $email = $user->getUserIdentifier();
        $name = $session->get('user_name', 'User');

        // Get user entity from database to get the user ID
        $userRepo = $em->getRepository(User::class);
        $userEntity = $userRepo->findOneBy(['email' => $email]);
        
        if (!$userEntity) {
            $logger->error('User entity not found for email: ' . $email);
            return $this->redirectToRoute('app_login');
        }

        $userId = $userEntity->getId();
        $logger->info('Found user entity', ['userId' => $userId, 'email' => $email]);

        // Check if user is in any household
        if (!$this->isUserInHousehold($em, $userId)) {
            $logger->info('User not in any household', ['userId' => $userId, 'email' => $email]);
            
            // Return view indicating user needs to join or create a household
            return $this->render('application/index.html.twig', [
                'name' => $name,
                'email' => $email,
                'userShops' => [],
                'shoppingLists' => [],
                'userHouseholdRole' => 'none',
                'requiresHousehold' => true,
                'message' => 'You need to join or create a household to start managing shopping lists.'
            ]);
        }

        // Get user's household ID (safe to call now since we confirmed user is in a household)
        $householdId = $this->getUserHouseholdId($em, $userId);
        $logger->info('Found household ID', ['householdId' => $householdId, 'userId' => $userId, 'email' => $email]);
        
        // Get owner's household ID for item queries
        $ownerHouseholdId = $this->getOwnerHouseholdId($em, $userId, $householdId);
        $logger->info('Found owner household ID', ['ownerHouseholdId' => $ownerHouseholdId, 'userHouseholdId' => $householdId]);
        
        // Get user's household role for UI permissions
        $userHouseholdRole = $this->getUserHouseholdRole($em, $userId, $householdId);
        $logger->info('Found user household role', ['role' => $userHouseholdRole, 'userId' => $userId, 'householdId' => $householdId]);
        
        // Get shops from all household members (including user's own shops)
        $userShops = $this->getHouseholdShops($em, $userId, $householdId);
        $logger->info('Found user shops', ['shopsCount' => count($userShops)]);

        // Get shopping lists grouped by shops (filtered by owner's household for persistence)
        $listsRepo = $em->getRepository(Lists::class);
        $shoppingLists = [];

        foreach ($userShops as $shop) {
            // Get all list items for this shop within the owner's household
            // Include both owner household and legacy household_id = 1 for backward compatibility
            $shopLists = $listsRepo->createQueryBuilder('l')
                ->where('l.shopId = :shopId')
                ->andWhere('l.householdId = :ownerHouseholdId OR l.householdId = 1')
                ->setParameter('shopId', $shop->getShopId())
                ->setParameter('ownerHouseholdId', $ownerHouseholdId)
                ->getQuery()
                ->getResult();
            
            $logger->info('Found shop items', [
                'shopId' => $shop->getShopId(),
                'shopName' => $shop->getName(),
                'itemsCount' => count($shopLists),
                'householdId' => $householdId
            ]);
            
            // Separate purchased and unpurchased items
            $unpurchasedItems = array_filter($shopLists, function($item) {
                return !$item->isPurchased();
            });
            
            $purchasedItems = array_filter($shopLists, function($item) {
                return $item->isPurchased();
            });
            
            $shoppingLists[] = [
                'shop' => $shop,
                'items' => $unpurchasedItems,
                'purchasedItems' => $purchasedItems
            ];
        }

        $logger->info('Shopping lists loaded', [
            'user' => $email,
            'userId' => $userId,
            'householdId' => $householdId,
            'shops_count' => count($userShops),
            'lists_count' => count($shoppingLists),
            'shop_names' => array_map(function($shop) { return $shop->getName(); }, $userShops)
        ]);

        // Convert shops to array format for JSON serialization
        $userShopsArray = array_map(function($shop) {
            return [
                'shopId' => $shop->getShopId(),
                'name' => $shop->getName(),
                'userId' => $shop->getUserId()
            ];
        }, $userShops);

        return $this->render('application/index.html.twig', [
            'name' => $name,
            'email' => $email,
            'userShops' => $userShopsArray,
            'shoppingLists' => $shoppingLists,
            'userHouseholdRole' => $userHouseholdRole // Add role for UI permissions
        ]);
    }

    /**
     * Add a new product to shopping list
     * POST /api/add-product - Adds product to specified shop's list
     */
    #[Route('/api/add-product', name: 'add_product', methods: ['POST'])]
    public function addProduct(Request $request, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Get request data
            $data = json_decode($request->getContent(), true);
            $productName = trim($data['productName'] ?? '');
            $quantity = (int)($data['quantity'] ?? 1);
            $shopId = (int)($data['shopId'] ?? 0);

            // Validate input
            if (empty($productName)) {
                return new JsonResponse(['error' => 'Product name is required'], 400);
            }

            if ($shopId <= 0) {
                return new JsonResponse(['error' => 'Valid shop selection is required'], 400);
            }

            if ($quantity <= 0) {
                return new JsonResponse(['error' => 'Quantity must be greater than 0'], 400);
            }

            // Get user's household ID first
            $userHouseholdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Get the owner's household ID to store items persistently
            $ownerHouseholdId = $this->getOwnerHouseholdId($em, $userEntity->getId(), $userHouseholdId);

            // Verify shop is accessible through user's household
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $householdShops = $this->getHouseholdShops($em, $userEntity->getId(), $userHouseholdId);
            
            $shop = null;
            foreach ($householdShops as $householdShop) {
                if ($householdShop->getShopId() == $shopId) {
                    $shop = $householdShop;
                    break;
                }
            }
            
            if (!$shop) {
                return new JsonResponse(['error' => 'Shop not found or not accessible to your household'], 404);
            }

            // Create new list item with owner's household ID for persistence
            $listItem = new Lists();
            $listItem->setListId(1); // For now, using default list ID
            $listItem->setHouseholdId($ownerHouseholdId); // Use owner's household ID for persistence
            $listItem->setShopId($shopId);
            $listItem->setItemId(0); // For now, using 0 as we don't have item catalog yet
            $listItem->setQuantity($quantity);
            $listItem->setLabel($productName);

            $em->persist($listItem);
            $em->flush();

            $logger->info('Product added to shopping list', [
                'user' => $userEntity->getEmail(),
                'userHouseholdId' => $userHouseholdId,
                'ownerHouseholdId' => $ownerHouseholdId,
                'product' => $productName,
                'quantity' => $quantity,
                'shop' => $shop->getName()
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Product '{$productName}' added to {$shop->getName()}'s list",
                'listItem' => [
                    'id' => $listItem->getId(),
                    'productName' => $productName,
                    'quantity' => $quantity,
                    'shopName' => $shop->getName()
                ]
            ]);

        } catch (\Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to add items to shopping lists',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error adding product to list: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Mark a product as purchased in shopping list
     * PUT /api/mark-purchased/{id} - Marks product as purchased instead of deleting
     */
    #[Route('/api/mark-purchased/{id}', name: 'mark_purchased', methods: ['PUT'])]
    public function markPurchased(int $id, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Get owner's household ID for item access
            $ownerHouseholdId = $this->getOwnerHouseholdId($em, $userEntity->getId(), $householdId);

            // Verify the item belongs to the owner's household (includes backward compatibility)
            if ($listItem->getHouseholdId() !== $ownerHouseholdId && $listItem->getHouseholdId() !== 1) {
                return new JsonResponse(['error' => 'Access denied to this household item'], 403);
            }

            // Mark as purchased
            $listItem->setPurchased(true);
            $em->flush();

            $logger->info('Product marked as purchased in shopping list', [
                'user' => $userEntity->getEmail(),
                'listItemId' => $id,
                'product' => $listItem->getLabel()
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product marked as purchased',
                'listItem' => [
                    'id' => $listItem->getId(),
                    'productName' => $listItem->getLabel(),
                    'quantity' => $listItem->getQuantity(),
                    'purchased' => $listItem->isPurchased()
                ]
            ]);

        } catch (\Exception $e) {
            $logger->error('Error marking product as purchased: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Unmark a product as purchased (mark as unpurchased)
     * PUT /api/unmark-purchased/{id} - Marks product as unpurchased
     */
    #[Route('/api/unmark-purchased/{id}', name: 'unmark_purchased', methods: ['PUT'])]
    public function unmarkPurchased(int $id, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Get owner's household ID for item access
            $ownerHouseholdId = $this->getOwnerHouseholdId($em, $userEntity->getId(), $householdId);

            // Verify the item belongs to the owner's household (includes backward compatibility)
            if ($listItem->getHouseholdId() !== $ownerHouseholdId && $listItem->getHouseholdId() !== 1) {
                return new JsonResponse(['error' => 'Access denied to this household item'], 403);
            }

            // Mark as unpurchased
            $listItem->setPurchased(false);
            $em->flush();

            $logger->info('Product marked as unpurchased in shopping list', [
                'user' => $userEntity->getEmail(),
                'listItemId' => $id,
                'product' => $listItem->getLabel()
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product marked as unpurchased',
                'listItem' => [
                    'id' => $listItem->getId(),
                    'productName' => $listItem->getLabel(),
                    'quantity' => $listItem->getQuantity(),
                    'purchased' => $listItem->isPurchased()
                ]
            ]);

        } catch (\Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to manage shopping lists',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error unmarking product as purchased: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Remove a product from shopping list (permanently delete)
     * DELETE /api/remove-product/{id} - Removes product from list completely
     */
    #[Route('/api/remove-product/{id}', name: 'remove_product', methods: ['DELETE'])]
    public function removeProduct(int $id, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Verify the item belongs to the user's household (include backward compatibility for legacy household_id=1)
            if ($listItem->getHouseholdId() !== $householdId && $listItem->getHouseholdId() !== 1) {
                return new JsonResponse(['error' => 'Access denied to this household item'], 403);
            }

            // Remove the item
            $em->remove($listItem);
            $em->flush();

            $logger->info('Product removed from shopping list', [
                'user' => $userEntity->getEmail(),
                'listItemId' => $id,
                'product' => $listItem->getLabel()
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product removed from list'
            ]);

        } catch (\Exception $e) {
            $logger->error('Error removing product from list: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Edit a product in shopping list
     * PUT /api/edit-product/{id} - Updates existing product in list
     */
    #[Route('/api/edit-product/{id}', name: 'edit_product', methods: ['PUT'])]
    public function editProduct(int $id, Request $request, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Get request data
            $data = json_decode($request->getContent(), true);
            $productName = trim($data['productName'] ?? '');
            $quantity = (int)($data['quantity'] ?? 1);
            $shopId = (int)($data['shopId'] ?? 0);

            // Validate input
            if (empty($productName)) {
                return new JsonResponse(['error' => 'Product name is required'], 400);
            }

            if ($quantity <= 0) {
                return new JsonResponse(['error' => 'Quantity must be greater than 0'], 400);
            }

            if ($shopId <= 0) {
                return new JsonResponse(['error' => 'Valid shop selection is required'], 400);
            }

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Verify the item belongs to the user's household
            if ($listItem->getHouseholdId() !== $householdId) {
                return new JsonResponse(['error' => 'Access denied to this household item'], 403);
            }

            // Verify both old and new shops are accessible through user's household
            $householdShops = $this->getHouseholdShops($em, $userEntity->getId(), $householdId);
            
            $currentShop = null;
            $newShop = null;
            
            foreach ($householdShops as $householdShop) {
                if ($householdShop->getShopId() == $listItem->getShopId()) {
                    $currentShop = $householdShop;
                }
                if ($householdShop->getShopId() == $shopId) {
                    $newShop = $householdShop;
                }
            }

            if (!$currentShop) {
                return new JsonResponse(['error' => 'Current shop not accessible to your household'], 403);
            }

            if (!$newShop) {
                return new JsonResponse(['error' => 'New shop not found or not accessible to your household'], 404);
            }

            // Update the list item
            $oldProductName = $listItem->getLabel();
            $oldQuantity = $listItem->getQuantity();
            $oldShopId = $listItem->getShopId();

            $listItem->setLabel($productName);
            $listItem->setQuantity($quantity);
            $listItem->setShopId($shopId);

            $em->flush();

            $logger->info('Product updated in shopping list', [
                'user' => $userEntity->getEmail(),
                'listItemId' => $id,
                'old_product' => $oldProductName,
                'new_product' => $productName,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $quantity,
                'old_shop_id' => $oldShopId,
                'new_shop_id' => $shopId
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Product updated successfully",
                'listItem' => [
                    'id' => $listItem->getId(),
                    'productName' => $productName,
                    'quantity' => $quantity,
                    'shopId' => $shopId,
                    'shopName' => $newShop->getName()
                ]
            ]);

        } catch (\Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to manage shopping lists',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error editing product in list: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Delete all purchased items for a specific shop
     * DELETE /api/delete-purchased-items/{shopId} - Permanently removes all purchased items for a shop
     */
    #[Route('/api/delete-purchased-items/{shopId}', name: 'delete_purchased_items', methods: ['DELETE'])]
    public function deletePurchasedItems(int $shopId, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Get owner's household ID for item operations
            $ownerHouseholdId = $this->getOwnerHouseholdId($em, $userEntity->getId(), $householdId);

            // Verify the shop is accessible through user's household
            $householdShops = $this->getHouseholdShops($em, $userEntity->getId(), $householdId);
            
            $shop = null;
            foreach ($householdShops as $householdShop) {
                if ($householdShop->getShopId() == $shopId) {
                    $shop = $householdShop;
                    break;
                }
            }

            if (!$shop) {
                return new JsonResponse(['error' => 'Shop not found or not accessible to your household'], 403);
            }

            // Find all purchased items for this shop in the owner's household (include backward compatibility for legacy household_id=1)
            $listsRepo = $em->getRepository(Lists::class);
            $queryBuilder = $listsRepo->createQueryBuilder('l')
                ->where('l.shopId = :shopId')
                ->andWhere('l.purchased = :purchased')
                ->andWhere('(l.householdId = :ownerHouseholdId OR l.householdId = 1)')
                ->setParameter('shopId', $shopId)
                ->setParameter('purchased', true)
                ->setParameter('ownerHouseholdId', $ownerHouseholdId);
            
            $purchasedItems = $queryBuilder->getQuery()->getResult();

            if (empty($purchasedItems)) {
                return new JsonResponse(['error' => 'No purchased items found for this shop'], 404);
            }

            $deletedCount = count($purchasedItems);

            // Delete all purchased items
            foreach ($purchasedItems as $item) {
                $em->remove($item);
            }
            $em->flush();

            $logger->info('All purchased items deleted for shop', [
                'user' => $userEntity->getEmail(),
                'shopId' => $shopId,
                'shopName' => $shop->getName(),
                'deletedCount' => $deletedCount
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Deleted {$deletedCount} purchased items from {$shop->getName()}"
            ]);

        } catch (\Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to manage shopping lists',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error deleting purchased items: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Revert all purchased items back to shopping list for a specific shop
     * PUT /api/revert-purchased-items/{shopId} - Marks all purchased items as unpurchased
     */
    #[Route('/api/revert-purchased-items/{shopId}', name: 'revert_purchased_items', methods: ['PUT'])]
    public function revertPurchasedItems(int $shopId, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userEntity->getId());

            // Verify the shop is accessible through user's household
            $householdShops = $this->getHouseholdShops($em, $userEntity->getId(), $householdId);
            
            $shop = null;
            foreach ($householdShops as $householdShop) {
                if ($householdShop->getShopId() == $shopId) {
                    $shop = $householdShop;
                    break;
                }
            }

            if (!$shop) {
                return new JsonResponse(['error' => 'Shop not found or not accessible to your household'], 403);
            }

            // Find all purchased items for this shop in the user's household
            $listsRepo = $em->getRepository(Lists::class);
            $purchasedItems = $listsRepo->findBy([
                'shopId' => $shopId, 
                'purchased' => true, 
                'householdId' => $householdId
            ]);

            if (empty($purchasedItems)) {
                return new JsonResponse(['error' => 'No purchased items found for this shop'], 404);
            }

            $revertedCount = count($purchasedItems);

            // Mark all purchased items as unpurchased
            foreach ($purchasedItems as $item) {
                $item->setPurchased(false);
            }
            $em->flush();

            $logger->info('All purchased items reverted for shop', [
                'user' => $userEntity->getEmail(),
                'shopId' => $shopId,
                'shopName' => $shop->getName(),
                'revertedCount' => $revertedCount
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Moved {$revertedCount} items from purchased back to {$shop->getName()} shopping list"
            ]);

        } catch (\Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to manage shopping lists',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error reverting purchased items: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Move an item between shops or change its purchased status
     * POST /api/move-item - Moves item to different shop or changes purchased status
     */
    #[Route('/api/move-item', name: 'move_item', methods: ['POST'])]
    public function moveItem(Request $request, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            $logger->info('Move item endpoint called');
            
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            $userId = $userEntity->getId();
            
            // Parse request data
            $data = json_decode($request->getContent(), true);
            $itemId = $data['itemId'] ?? null;
            $targetShopId = $data['targetShopId'] ?? null;
            $isPurchased = $data['isPurchased'] ?? false;

            $logger->info('Move item data:', [
                'itemId' => $itemId,
                'targetShopId' => $targetShopId,
                'isPurchased' => $isPurchased,
                'userId' => $userId
            ]);

            // Validate input
            if (!$itemId || !$targetShopId) {
                return new JsonResponse(['error' => 'Missing itemId or targetShopId'], 400);
            }

            // Get user's household ID
            $householdId = $this->getUserHouseholdId($em, $userId);

            // Get the item from database
            $listsRepo = $em->getRepository(Lists::class);
            $item = $listsRepo->find($itemId);

            if (!$item) {
                return new JsonResponse(['error' => 'Item not found'], 404);
            }

            // Verify the item belongs to the user's household
            if ($item->getHouseholdId() !== $householdId) {
                return new JsonResponse(['error' => 'Access denied to this household item'], 403);
            }

            // Get shops accessible through user's household
            $householdShops = $this->getHouseholdShops($em, $userId, $householdId);
            
            // Verify current shop is accessible
            $currentShop = null;
            $targetShop = null;
            
            foreach ($householdShops as $householdShop) {
                if ($householdShop->getShopId() == $item->getShopId()) {
                    $currentShop = $householdShop;
                }
                if ($householdShop->getShopId() == $targetShopId) {
                    $targetShop = $householdShop;
                }
            }

            if (!$currentShop) {
                return new JsonResponse(['error' => 'Current shop not accessible to your household'], 403);
            }

            if (!$targetShop) {
                return new JsonResponse(['error' => 'Target shop not found or not accessible to your household'], 404);
            }

            // Update item shop and purchased status
            $item->setShopId($targetShopId);
            $item->setPurchased($isPurchased);

            $em->persist($item);
            $em->flush();

            $logger->info('Item moved successfully', [
                'itemId' => $itemId,
                'oldShopId' => $item->getShopId(),
                'newShopId' => $targetShopId,
                'isPurchased' => $isPurchased,
                'householdId' => $householdId
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Item moved successfully'
            ]);

        } catch (\Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to manage shopping lists',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error moving item: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Helper method to check if user is in any household without auto-creating one
     * Returns true if user is in a household, false if not
     */
    private function isUserInHousehold(EntityManagerInterface $em, int $userId): bool
    {
        $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
        $userRepo = $em->getRepository(User::class);
        
        // Get the user entity to get the email
        $userEntity = $userRepo->find($userId);
        if (!$userEntity) {
            return false;
        }
        
        $userEmail = $userEntity->getEmail();
        
        // Search by both integer ID and email for backward compatibility
        $userMemberships = $householdUsersRepo->createQueryBuilder('hu')
            ->where('hu.userId = :userId OR hu.userId = :userEmail')
            ->andWhere('hu.status = :status')
            ->setParameter('userId', (string)$userId)
            ->setParameter('userEmail', $userEmail)
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getResult();
        
        // Check if user has any active household memberships
        foreach ($userMemberships as $membership) {
            if (in_array($membership->getRole(), ['owner', 'member'])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Helper method to validate user is in household and get user entity
     * Returns array with userEntity and error response, or null if validation passed
     */
    private function validateUserInHousehold(EntityManagerInterface $em, Security $security): ?array
    {
        $user = $security->getUser();
        if (!$user) {
            return [
                'userEntity' => null,
                'error' => new JsonResponse(['error' => 'User not authenticated'], 401)
            ];
        }

        // Get user entity from database
        $userRepo = $em->getRepository(User::class);
        $userEntity = $userRepo->findOneBy(['email' => $user->getUserIdentifier()]);
        
        if (!$userEntity) {
            return [
                'userEntity' => null,
                'error' => new JsonResponse(['error' => 'User entity not found'], 404)
            ];
        }

        // Check if user is in any household
        if (!$this->isUserInHousehold($em, $userEntity->getId())) {
            return [
                'userEntity' => $userEntity,
                'error' => new JsonResponse([
                    'error' => 'You must be part of a household to manage shopping lists',
                    'requiresHousehold' => true
                ], 403)
            ];
        }

        return [
            'userEntity' => $userEntity,
            'error' => null
        ];
    }

    /**
     * Helper method to get user's active household ID
     * Prioritizes households with more members for collaboration
     * Does NOT auto-create households - returns existing household ID only
     */
    private function getUserHouseholdId(EntityManagerInterface $em, int $userId): int
    {
        $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
        $userRepo = $em->getRepository(User::class);
        
        // Get the user entity to get the email
        $userEntity = $userRepo->find($userId);
        if (!$userEntity) {
            throw new \Exception('User entity not found');
        }
        
        $userEmail = $userEntity->getEmail();
        
        // Search by both integer ID and email for backward compatibility
        $userMemberships = $householdUsersRepo->createQueryBuilder('hu')
            ->where('hu.userId = :userId OR hu.userId = :userEmail')
            ->andWhere('hu.status = :status')
            ->setParameter('userId', (string)$userId)
            ->setParameter('userEmail', $userEmail)
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getResult();
        
        if (empty($userMemberships)) {
            throw new \Exception('User is not part of any household');
        }
        
        // If user has multiple households, prioritize the one with the most members (collaborative household)
        $householdMemberCounts = [];
        foreach ($userMemberships as $membership) {
            if (in_array($membership->getRole(), ['owner', 'member'])) {
                $householdId = $membership->getHouseholdId();
                if (!isset($householdMemberCounts[$householdId])) {
                    // Count members in this household
                    $memberCount = $householdUsersRepo->createQueryBuilder('hu2')
                        ->select('COUNT(hu2.id)')
                        ->where('hu2.householdId = :householdId')
                        ->andWhere('hu2.status = :status')
                        ->setParameter('householdId', $householdId)
                        ->setParameter('status', 'approved')
                        ->getQuery()
                        ->getSingleScalarResult();
                    
                    $householdMemberCounts[$householdId] = [
                        'count' => $memberCount,
                        'householdId' => $householdId
                    ];
                }
            }
        }
        
        if (!empty($householdMemberCounts)) {
            // Sort by member count (descending) to prioritize collaborative households
            uasort($householdMemberCounts, function($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            
            // Return the household with the most members
            $prioritizedHousehold = reset($householdMemberCounts);
            return $prioritizedHousehold['householdId'];
        }
        
        throw new \Exception('No valid household memberships found');
    }

    /**
     * Helper method to safely get user's household ID for display purposes
     * Returns null if user is not in any household (doesn't throw exception)
     */
    private function getUserHouseholdIdSafe(EntityManagerInterface $em, int $userId): ?int
    {
        try {
            return $this->getUserHouseholdId($em, $userId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Helper method to get shops from household owner only
     * Members can see and use owner's shops, but their own shops are not shared
     */
    private function getHouseholdShops(EntityManagerInterface $em, int $userId, int $householdId): array
    {
        $shopsRepo = $em->getRepository(CreatedShops::class);
        $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
        $userRepo = $em->getRepository(User::class);
        
        // Find the household owner
        $ownerMembership = $householdUsersRepo->createQueryBuilder('hu')
            ->where('hu.householdId = :householdId')
            ->andWhere('hu.role = :role')
            ->andWhere('hu.status = :status')
            ->setParameter('householdId', $householdId)
            ->setParameter('role', 'owner')
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$ownerMembership) {
            // If no owner found, fall back to user's own shops
            $userEntity = $userRepo->find($userId);
            if (!$userEntity) {
                return [];
            }
            
            $userEmail = $userEntity->getEmail();
            
            return $shopsRepo->createQueryBuilder('s')
                ->where('s.userId = :userId OR s.userId = :userEmail')
                ->setParameter('userId', (string)$userId)
                ->setParameter('userEmail', $userEmail)
                ->getQuery()
                ->getResult();
        }
        
        $ownerUserId = $ownerMembership->getUserId();
        
        // Handle both string (email) and integer userId formats in HouseholdUsers
        if (is_numeric($ownerUserId)) {
            $ownerUserIdInt = (int)$ownerUserId;
            // Get the user entity
            $ownerUserEntity = $userRepo->find($ownerUserIdInt);
        } else {
            // If it's an email, find the corresponding user
            $ownerUserEntity = $userRepo->findOneBy(['email' => $ownerUserId]);
            if ($ownerUserEntity) {
                $ownerUserIdInt = $ownerUserEntity->getId();
            } else {
                return []; // Owner user not found
            }
        }
        
        if (!$ownerUserEntity) {
            return []; // Owner user not found
        }
        
        $ownerEmail = $ownerUserEntity->getEmail();
        
        // Get only the owner's shops
        $ownerShops = $shopsRepo->createQueryBuilder('s')
            ->where('s.userId = :userId OR s.userId = :userEmail')
            ->setParameter('userId', (string)$ownerUserIdInt)
            ->setParameter('userEmail', $ownerEmail)
            ->getQuery()
            ->getResult();
        
        return $ownerShops;
    }

    /**
     * Helper method to check if a user has access to a specific shop through their household
     */
    private function hasShopAccess(EntityManagerInterface $em, int $userId, int $householdId, int $shopId): bool
    {
        $householdShops = $this->getHouseholdShops($em, $userId, $householdId);
        
        foreach ($householdShops as $householdShop) {
            if ($householdShop->getShopId() === $shopId) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Helper method to get the owner's household ID for a given household
     * This ensures items are stored under the owner's household for persistence
     */
    private function getOwnerHouseholdId(EntityManagerInterface $em, int $userId, int $userHouseholdId): int
    {
        $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
        
        // Check if current user is the owner
        $userMembership = $householdUsersRepo->createQueryBuilder('hu')
            ->where('hu.householdId = :householdId')
            ->andWhere('(hu.userId = :userId OR hu.userId = :userEmail)')
            ->andWhere('hu.status = :status')
            ->setParameter('householdId', $userHouseholdId)
            ->setParameter('userId', (string)$userId)
            ->setParameter('userEmail', $this->getUserEmail($em, $userId))
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getOneOrNullResult();
        
        if ($userMembership && $userMembership->getRole() === 'owner') {
            // User is the owner, use their household ID
            return $userHouseholdId;
        }
        
        // User is a member, find the owner's household
        $ownerMembership = $householdUsersRepo->createQueryBuilder('hu')
            ->where('hu.householdId = :householdId')
            ->andWhere('hu.role = :role')
            ->andWhere('hu.status = :status')
            ->setParameter('householdId', $userHouseholdId)
            ->setParameter('role', 'owner')
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getOneOrNullResult();
        
        if ($ownerMembership) {
            return $ownerMembership->getHouseholdId();
        }
        
        // Fallback to user's household if no owner found
        return $userHouseholdId;
    }
    
    /**
     * Helper method to get user's email by ID
     */
    private function getUserEmail(EntityManagerInterface $em, int $userId): string
    {
        $userRepo = $em->getRepository(User::class);
        $user = $userRepo->find($userId);
        return $user ? $user->getEmail() : '';
    }

    /**
     * Helper method to get user's role in their current household
     * Returns 'owner', 'member', or 'none' if not in any household
     */
    private function getUserHouseholdRole(EntityManagerInterface $em, int $userId, int $householdId): string
    {
        $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
        $userRepo = $em->getRepository(User::class);
        
        // Get the user entity to get the email
        $userEntity = $userRepo->find($userId);
        if (!$userEntity) {
            return 'none';
        }
        
        $userEmail = $userEntity->getEmail();
        
        // Find user's membership in the specified household
        $userMembership = $householdUsersRepo->createQueryBuilder('hu')
            ->where('hu.householdId = :householdId')
            ->andWhere('(hu.userId = :userId OR hu.userId = :userEmail)')
            ->andWhere('hu.status = :status')
            ->setParameter('householdId', $householdId)
            ->setParameter('userId', (string)$userId)
            ->setParameter('userEmail', $userEmail)
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getOneOrNullResult();
        
        if ($userMembership) {
            return $userMembership->getRole(); // Returns 'owner' or 'member'
        }
        
        return 'none'; // User is not in any household
    }

    /**
     * Setup shops for the current user
     * POST /application/setup-shops - Add multiple shops to the user's account
     */
    #[Route('/application/setup-shops', name: 'app_setup_shops', methods: ['POST'])]
    public function setupShops(Request $request, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['success' => false, 'error' => 'User not authenticated'], 401);
            }

            $email = $user->getUserIdentifier();

            // Get user from database
            $userRepo = $em->getRepository(User::class);
            $userEntity = $userRepo->findOneBy(['email' => $email]);
            if (!$userEntity) {
                return new JsonResponse(['success' => false, 'error' => 'User not found'], 404);
            }

            // Check if user is an owner of any household
            $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
            $ownerMembership = $householdUsersRepo->createQueryBuilder('hu')
                ->where('hu.userId = :userId OR hu.userId = :userEmail')
                ->andWhere('hu.role = :role')
                ->andWhere('hu.status = :status')
                ->setParameter('userId', (string)$userEntity->getId())
                ->setParameter('userEmail', $email)
                ->setParameter('role', 'owner')
                ->setParameter('status', 'approved')
                ->getQuery()
                ->getOneOrNullResult();

            if (!$ownerMembership) {
                return new JsonResponse(['success' => false, 'error' => 'Only household owners can create shops'], 403);
            }

            $householdId = $ownerMembership->getHouseholdId();
            $logger->info('Shop creation by household owner', [
                'user' => $email,
                'userId' => $userEntity->getId(),
                'householdId' => $householdId,
                'role' => 'owner'
            ]);

            // Get request data
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['shops']) || !is_array($data['shops'])) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid request data'], 400);
            }

            $shopNames = array_filter(array_map('trim', $data['shops']));
            if (empty($shopNames)) {
                return new JsonResponse(['success' => false, 'error' => 'No shop names provided'], 400);
            }

            $shopsRepo = $em->getRepository(CreatedShops::class);
            $createdShops = [];

            // Generate starting shopId
            $maxShopId = $em->createQuery('SELECT MAX(s.shopId) FROM App\Entity\CreatedShops s')
                ->getSingleScalarResult();
            $nextShopId = ($maxShopId ?? 0) + 1;

            foreach ($shopNames as $shopName) {
                // Check if shop already exists for this user
                $existingShop = $shopsRepo->findOneBy([
                    'name' => $shopName,
                    'userId' => $userEntity->getId()
                ]);

                if ($existingShop) {
                    continue; // Skip if shop already exists
                }

                // Create new shop with incremented shopId
                $shop = new CreatedShops();
                $shop->setShopId($nextShopId);
                $shop->setName($shopName);
                $shop->setUserId((string)$userEntity->getId()); // Ensure string format for consistency

                $em->persist($shop);
                $createdShops[] = $shopName;
                $nextShopId++; // Increment for next shop
            }

            $em->flush();

            $logger->info('Shops created successfully', [
                'user' => $email,
                'householdId' => $householdId,
                'created_shops' => $createdShops,
                'count' => count($createdShops)
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Shops created successfully',
                'created_shops' => $createdShops,
                'count' => count($createdShops)
            ]);

        } catch (\Exception $e) {
            $logger->error('Error creating shops: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to create shops: ' . $e->getMessage()
            ], 500);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to create shops: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug endpoint to check user shops
     * GET /application/debug-shops - Shows what shops exist for the current user
     */
    #[Route('/application/debug-shops', name: 'app_debug_shops', methods: ['GET'])]
    public function debugShops(EntityManagerInterface $em, Security $security): JsonResponse
    {
        try {
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $email = $user->getUserIdentifier();

            // Get user entity from database
            $userRepo = $em->getRepository(User::class);
            $userEntity = $userRepo->findOneBy(['email' => $email]);
            
            if (!$userEntity) {
                return new JsonResponse(['error' => 'User entity not found'], 404);
            }

            $userId = $userEntity->getId();

            // Get user's household ID and role
            $householdId = $this->getUserHouseholdId($em, $userId);
            $userRole = $this->getUserHouseholdRole($em, $userId, $householdId);
            
            // Get user's own shops (not shared in household)
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $userOwnShops = $shopsRepo->createQueryBuilder('s')
                ->where('s.userId = :userId OR s.userId = :userEmail')
                ->setParameter('userId', (string)$userId)
                ->setParameter('userEmail', $email)
                ->getQuery()
                ->getResult();
            
            // Get household shops (owner's shops only)
            $householdShops = $this->getHouseholdShops($em, $userId, $householdId);

            return new JsonResponse([
                'user_email' => $email,
                'user_id' => $userId,
                'household_id' => $householdId,
                'user_role' => $userRole,
                'user_own_shops_count' => count($userOwnShops),
                'user_own_shops' => array_map(function($shop) {
                    return [
                        'id' => $shop->getId(),
                        'shop_id' => $shop->getShopId(),
                        'name' => $shop->getName(),
                        'user_id' => $shop->getUserId()
                    ];
                }, $userOwnShops),
                'household_shops_count' => count($householdShops),
                'household_shops' => array_map(function($shop) {
                    return [
                        'id' => $shop->getId(),
                        'shop_id' => $shop->getShopId(),
                        'name' => $shop->getName(),
                        'user_id' => $shop->getUserId(),
                        'note' => 'Owner shops only'
                    ];
                }, $householdShops),
                'explanation' => [
                    'owner_shops_shown' => 'Only household owner shops are displayed in the application',
                    'member_shops_hidden' => 'Member shops are not included in household view',
                    'current_behavior' => $userRole === 'owner' ? 'You see your own shops' : 'You see only owner shops'
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Debug failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove a shop and all its associated list items
     * POST /api/remove-shop - Removes shop and all shopping list items
     */
    #[Route('/api/remove-shop', name: 'remove_shop', methods: ['POST'])]
    public function removeShop(Request $request, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Validate user is in household
            $validation = $this->validateUserInHousehold($em, $security);
            if ($validation['error']) {
                return $validation['error'];
            }
            $userEntity = $validation['userEntity'];

            // Get request data
            $data = json_decode($request->getContent(), true);
            $shopId = (int)($data['shopId'] ?? 0);

            if ($shopId <= 0) {
                return new JsonResponse(['error' => 'Valid shop ID is required'], 400);
            }

            $userId = $userEntity->getId();
            $householdId = $this->getUserHouseholdId($em, $userId);

            // Verify the shop belongs to the user's household
            $shopRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopRepo->findOneBy(['shopId' => $shopId]);
            
            if (!$shop) {
                return new JsonResponse(['error' => 'Shop not found'], 404);
            }

            // Check if this shop is accessible by the user's household
            $householdShops = $this->getHouseholdShops($em, $userId, $householdId);
            $hasAccess = false;
            foreach ($householdShops as $householdShop) {
                if ($householdShop->getShopId() === $shopId) {
                    $hasAccess = true;
                    break;
                }
            }

            if (!$hasAccess) {
                return new JsonResponse(['error' => 'You do not have permission to delete this shop'], 403);
            }

            // Remove all list items for this shop in the user's household (include backward compatibility for legacy household_id=1)
            $listsRepo = $em->getRepository(Lists::class);
            $queryBuilder = $listsRepo->createQueryBuilder('l')
                ->where('l.shopId = :shopId')
                ->andWhere('(l.householdId = :householdId OR l.householdId = 1)')
                ->setParameter('shopId', $shopId)
                ->setParameter('householdId', $householdId);
            
            $listItems = $queryBuilder->getQuery()->getResult();

            foreach ($listItems as $listItem) {
                $em->remove($listItem);
            }

            // Remove the shop itself
            $em->remove($shop);
            $em->flush();

            $logger->info('Shop removed successfully', [
                'shopId' => $shopId,
                'shopName' => $shop->getName(),
                'removedItems' => count($listItems),
                'userId' => $userId,
                'householdId' => $householdId
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Shop and all associated items removed successfully',
                'removedItems' => count($listItems)
            ]);

        } catch (Exception $e) {
            // Check if this is a household-related error
            if (strpos($e->getMessage(), 'User is not part of any household') !== false || 
                strpos($e->getMessage(), 'No valid household memberships found') !== false) {
                return new JsonResponse([
                    'error' => 'You must be part of a household to manage shops',
                    'requiresHousehold' => true
                ], 403);
            }
            
            $logger->error('Error removing shop: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to remove shop'], 500);
        }
    }

    /**
     * Get location-based shop suggestions for the user
     * GET /api/location-shops - Returns suggested shops based on user's location
     */
    #[Route('/api/location-shops', name: 'location_shops', methods: ['GET'])]
    public function getLocationBasedShops(Request $request, EntityManagerInterface $em, Security $security, LocationService $locationService, LoggerInterface $logger): JsonResponse
    {
        try {
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $userRepo = $em->getRepository(User::class);
            $userEntity = $userRepo->findOneBy(['email' => $user->getUserIdentifier()]);
            
            if (!$userEntity) {
                return new JsonResponse(['error' => 'User entity not found'], 404);
            }

            // Check if user already has location data
            $countryCode = $userEntity->getCountryCode();
            $country = $userEntity->getCountry();
            $region = $userEntity->getRegion();

            // If no location data, try to detect it
            if (!$countryCode) {
                $clientIp = $request->getClientIp();
                $locationData = $locationService->detectLocationFromIP($clientIp);
                
                if ($locationData) {
                    // Update user with detected location
                    $userEntity->setCountryCode($locationData['countryCode']);
                    $userEntity->setCountry($locationData['country']);
                    $userEntity->setRegion($locationData['region']);
                    $userEntity->setDetectedFrom($locationData['detectedFrom']);
                    
                    $em->flush();
                    
                    $countryCode = $locationData['countryCode'];
                    $country = $locationData['country'];
                    $region = $locationData['region'];
                    
                    $logger->info('User location detected and saved', [
                        'user' => $user->getUserIdentifier(),
                        'countryCode' => $countryCode,
                        'country' => $country,
                        'region' => $region,
                        'detectedFrom' => $locationData['detectedFrom']
                    ]);
                }
            }

            // Get suggested shops
            $suggestedShops = [];
            if ($countryCode) {
                $suggestedShops = $locationService->getSuggestedShops($countryCode, $region);
            } else {
                // Fallback to international shops if location detection failed
                $suggestedShops = $locationService->getSuggestedShops('INTERNATIONAL');
            }

            return new JsonResponse([
                'success' => true,
                'location' => [
                    'countryCode' => $countryCode,
                    'country' => $country,
                    'region' => $region,
                    'detectedFrom' => $userEntity->getDetectedFrom()
                ],
                'suggestedShops' => $suggestedShops,
                'shopCount' => count($suggestedShops)
            ]);

        } catch (\Exception $e) {
            $logger->error('Error getting location-based shops: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Failed to get shop suggestions'], 500);
        }
    }

    /**
     * Update user's location manually
     * POST /api/update-location - Allows user to manually set their location
     */
    #[Route('/api/update-location', name: 'update_location', methods: ['POST'])]
    public function updateLocation(Request $request, EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $userRepo = $em->getRepository(User::class);
            $userEntity = $userRepo->findOneBy(['email' => $user->getUserIdentifier()]);
            
            if (!$userEntity) {
                return new JsonResponse(['error' => 'User entity not found'], 404);
            }

            // Get request data
            $data = json_decode($request->getContent(), true);
            $countryCode = trim($data['countryCode'] ?? '');
            $country = trim($data['country'] ?? '');
            $region = trim($data['region'] ?? '');

            // Validate input
            if (empty($countryCode) || empty($country)) {
                return new JsonResponse(['error' => 'Country code and country name are required'], 400);
            }

            // Update user location
            $userEntity->setCountryCode(strtoupper($countryCode));
            $userEntity->setCountry($country);
            $userEntity->setRegion($region);
            $userEntity->setDetectedFrom('manual');
            
            $em->flush();

            $logger->info('User location updated manually', [
                'user' => $user->getUserIdentifier(),
                'countryCode' => $countryCode,
                'country' => $country,
                'region' => $region
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Location updated successfully',
                'location' => [
                    'countryCode' => strtoupper($countryCode),
                    'country' => $country,
                    'region' => $region,
                    'detectedFrom' => 'manual'
                ]
            ]);

        } catch (\Exception $e) {
            $logger->error('Error updating user location: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Failed to update location'], 500);
        }
    }

    /**
     * Debug endpoint to test location service with sample data
     * GET /api/debug-location - Test location detection with sample countries
     */
    #[Route('/api/debug-location', name: 'debug_location', methods: ['GET'])]
    public function debugLocation(LocationService $locationService, LoggerInterface $logger): JsonResponse
    {
        try {
            // Test with different country codes
            $testCountries = ['HU', 'US', 'AT', 'DE', 'UNKNOWN'];
            $results = [];

            foreach ($testCountries as $countryCode) {
                $shops = $locationService->getSuggestedShops($countryCode, null);
                $results[$countryCode] = [
                    'shopCount' => count($shops),
                    'shops' => array_slice($shops, 0, 5) // First 5 shops for brevity
                ];
            }

            return new JsonResponse([
                'success' => true,
                'testResults' => $results,
                'message' => 'Location service test completed'
            ]);

        } catch (\Exception $e) {
            $logger->error('Error testing location service: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Location service test failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete household (Owner only) - Cascades to all related data
     * DELETE /api/household/delete - Deletes household and all associated data
     */
    #[Route('/api/household/delete', name: 'delete_household', methods: ['DELETE'])]
    public function deleteHousehold(EntityManagerInterface $em, Security $security, LoggerInterface $logger): JsonResponse
    {
        try {
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $userRepo = $em->getRepository(User::class);
            $userEntity = $userRepo->findOneBy(['email' => $user->getUserIdentifier()]);
            
            if (!$userEntity) {
                return new JsonResponse(['error' => 'User entity not found'], 404);
            }

            $userId = $userEntity->getId();
            $userEmail = $userEntity->getEmail();

            $logger->info('Starting household deletion process', [
                'user' => $userEmail,
                'userId' => $userId
            ]);

            // Check if user is in any household and is the owner
            $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
            $ownerMembership = $householdUsersRepo->createQueryBuilder('hu')
                ->where('(hu.userId = :userId OR hu.userId = :userEmail)')
                ->andWhere('hu.role = :role')
                ->andWhere('hu.status = :status')
                ->setParameter('userId', (string)$userId)
                ->setParameter('userEmail', $userEmail)
                ->setParameter('role', 'owner')
                ->setParameter('status', 'approved')
                ->getQuery()
                ->getOneOrNullResult();

            if (!$ownerMembership) {
                $logger->warning('User attempted to delete household but is not an owner', [
                    'user' => $userEmail,
                    'userId' => $userId
                ]);
                return new JsonResponse(['error' => 'Only household owners can delete households'], 403);
            }

            $householdId = $ownerMembership->getHouseholdId();
            
            $logger->info('Found owner membership', [
                'user' => $userEmail,
                'householdId' => $householdId
            ]);

            // Start transaction for data consistency
            $em->beginTransaction();

            try {
                // 1. Delete all shopping list items for this household
                $listsRepo = $em->getRepository(Lists::class);
                $householdItems = $listsRepo->createQueryBuilder('l')
                    ->where('l.householdId = :householdId')
                    ->setParameter('householdId', $householdId)
                    ->getQuery()
                    ->getResult();

                $deletedItemsCount = count($householdItems);
                foreach ($householdItems as $item) {
                    $em->remove($item);
                }
                
                $logger->info('Deleted shopping list items', [
                    'householdId' => $householdId,
                    'deletedItems' => $deletedItemsCount
                ]);

                // 2. Delete all household user memberships
                $householdMemberships = $householdUsersRepo->createQueryBuilder('hu')
                    ->where('hu.householdId = :householdId')
                    ->setParameter('householdId', $householdId)
                    ->getQuery()
                    ->getResult();

                $deletedMembershipsCount = count($householdMemberships);
                foreach ($householdMemberships as $membership) {
                    $em->remove($membership);
                }
                
                $logger->info('Deleted household memberships', [
                    'householdId' => $householdId,
                    'deletedMemberships' => $deletedMembershipsCount
                ]);

                // 3. Delete the household itself
                $householdRepo = $em->getRepository(Household::class);
                $household = $householdRepo->findOneBy(['householdID' => $householdId]);
                
                $logger->info('Looking for household to delete', [
                    'householdId' => $householdId,
                    'householdFound' => $household ? 'yes' : 'no'
                ]);
                
                if (!$household) {
                    $logger->error('Household entity not found in database', [
                        'householdId' => $householdId,
                        'user' => $userEmail
                    ]);
                    throw new \Exception('Household not found with ID: ' . $householdId);
                }

                $householdName = $household->getName();
                $em->remove($household);
                
                $logger->info('Removed household entity', [
                    'householdId' => $householdId,
                    'householdName' => $householdName
                ]);

                // 4. Update user's household reference (if exists in User entity)
                if ($userEntity->getHouseholdId() === $householdId) {
                    $userEntity->setHouseholdId(null);
                }

                // Commit the transaction
                $em->flush();
                $em->commit();

                $logger->info('Household deleted successfully', [
                    'user' => $userEmail,
                    'householdId' => $householdId,
                    'householdName' => $householdName,
                    'deletedItems' => $deletedItemsCount,
                    'deletedMemberships' => $deletedMembershipsCount
                ]);

                return new JsonResponse([
                    'success' => true,
                    'message' => "Household '{$householdName}' has been deleted successfully",
                    'details' => [
                        'householdName' => $householdName,
                        'deletedItems' => $deletedItemsCount,
                        'deletedMemberships' => $deletedMembershipsCount
                    ]
                ]);

            } catch (\Exception $e) {
                // Rollback transaction on error
                $em->rollback();
                $logger->error('Transaction rolled back due to error', [
                    'error' => $e->getMessage(),
                    'householdId' => $householdId ?? 'unknown'
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            $logger->error('Error deleting household: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse([
                'error' => 'Failed to delete household: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user profile (Complete account deletion) - Cascades to all user data
     * DELETE /api/profile/delete - Deletes user account and all associated data
     */
    #[Route('/api/profile/delete', name: 'delete_profile', methods: ['DELETE'])]
    public function deleteProfile(EntityManagerInterface $em, Security $security, LoggerInterface $logger, SessionInterface $session, Request $request): JsonResponse
    {
        try {
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $userRepo = $em->getRepository(User::class);
            $userEntity = $userRepo->findOneBy(['email' => $user->getUserIdentifier()]);
            
            if (!$userEntity) {
                return new JsonResponse(['error' => 'User entity not found'], 404);
            }

            $userId = $userEntity->getId();
            $userEmail = $userEntity->getEmail();
            $userName = $userEntity->getName();

            $logger->info('Starting profile deletion process', [
                'user' => $userEmail,
                'userId' => $userId,
                'userName' => $userName
            ]);

            // Store user info before deletion for the response
            $userInfo = [
                'userName' => $userName,
                'userEmail' => $userEmail
            ];

            // Start transaction for data consistency
            $em->beginTransaction();

            try {
                $deletionSummary = [
                    'userName' => $userName,
                    'userEmail' => $userEmail,
                    'deletedItems' => 0,
                    'deletedShops' => 0,
                    'deletedMemberships' => 0,
                    'deletedHouseholds' => 0,
                    'householdActions' => []
                ];

                // 1. Check user's household memberships
                $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
                $userMemberships = $householdUsersRepo->createQueryBuilder('hu')
                    ->where('(hu.userId = :userId OR hu.userId = :userEmail)')
                    ->andWhere('hu.status = :status')
                    ->setParameter('userId', (string)$userId)
                    ->setParameter('userEmail', $userEmail)
                    ->setParameter('status', 'approved')
                    ->getQuery()
                    ->getResult();

                $logger->info('Found user memberships', [
                    'user' => $userEmail,
                    'membershipCount' => count($userMemberships)
                ]);

                // 2. Handle household memberships
                foreach ($userMemberships as $membership) {
                    $householdId = $membership->getHouseholdId();
                    $role = $membership->getRole();

                    if ($role === 'owner') {
                        // User is owner - delete the entire household
                        $logger->info('User is household owner, deleting household', [
                            'user' => $userEmail,
                            'householdId' => $householdId
                        ]);

                        // Delete all shopping list items for this household
                        $listsRepo = $em->getRepository(Lists::class);
                        $householdItems = $listsRepo->createQueryBuilder('l')
                            ->where('l.householdId = :householdId')
                            ->setParameter('householdId', $householdId)
                            ->getQuery()
                            ->getResult();

                        foreach ($householdItems as $item) {
                            $em->remove($item);
                        }
                        $deletionSummary['deletedItems'] += count($householdItems);

                        // Delete all household memberships
                        $allHouseholdMemberships = $householdUsersRepo->createQueryBuilder('hu')
                            ->where('hu.householdId = :householdId')
                            ->setParameter('householdId', $householdId)
                            ->getQuery()
                            ->getResult();

                        foreach ($allHouseholdMemberships as $membershipToDelete) {
                            $em->remove($membershipToDelete);
                        }
                        $deletionSummary['deletedMemberships'] += count($allHouseholdMemberships);

                        // Delete the household itself
                        $householdRepo = $em->getRepository(Household::class);
                        $household = $householdRepo->findOneBy(['householdID' => $householdId]);
                        
                        if ($household) {
                            $householdName = $household->getName();
                            $em->remove($household);
                            $deletionSummary['deletedHouseholds']++;
                            $deletionSummary['householdActions'][] = "Deleted household '{$householdName}' (owner)";
                        }

                    } else {
                        // User is member - just remove their membership
                        $logger->info('User is household member, removing membership only', [
                            'user' => $userEmail,
                            'householdId' => $householdId,
                            'role' => $role
                        ]);

                        $em->remove($membership);
                        $deletionSummary['deletedMemberships']++;
                        $deletionSummary['householdActions'][] = "Left household {$householdId} (member)";
                    }
                }

                // 3. Delete all user's shopping list items (items they created)
                $listsRepo = $em->getRepository(Lists::class);
                $userItems = $listsRepo->createQueryBuilder('l')
                    ->where('l.householdId = :userId OR l.householdId = 1') // Include legacy items
                    ->setParameter('userId', $userId)
                    ->getQuery()
                    ->getResult();

                foreach ($userItems as $item) {
                    $em->remove($item);
                }
                $deletionSummary['deletedItems'] += count($userItems);

                $logger->info('Deleted user shopping list items', [
                    'user' => $userEmail,
                    'deletedItems' => count($userItems)
                ]);

                // 4. Delete all user's created shops
                $shopsRepo = $em->getRepository(CreatedShops::class);
                $userShops = $shopsRepo->createQueryBuilder('s')
                    ->where('s.userId = :userId OR s.userId = :userEmail')
                    ->setParameter('userId', (string)$userId)
                    ->setParameter('userEmail', $userEmail)
                    ->getQuery()
                    ->getResult();

                foreach ($userShops as $shop) {
                    $em->remove($shop);
                }
                $deletionSummary['deletedShops'] = count($userShops);

                $logger->info('Deleted user shops', [
                    'user' => $userEmail,
                    'deletedShops' => count($userShops)
                ]);

                // 5. Delete the user entity itself
                $em->remove($userEntity);

                $logger->info('Removed user entity', [
                    'user' => $userEmail,
                    'userId' => $userId
                ]);

                // Commit the transaction
                $em->flush();
                $em->commit();

                $logger->info('Profile deleted successfully', [
                    'user' => $userEmail,
                    'userId' => $userId,
                    'deletionSummary' => $deletionSummary
                ]);

                // NOW handle the session cleanup AFTER successful deletion
                // Clear the security token and session
                $security->logout(false);
                $session->invalidate();

                return new JsonResponse([
                    'success' => true,
                    'message' => "Profile for '{$userName}' has been deleted successfully",
                    'details' => $deletionSummary,
                    'redirect' => '/farewell'
                ]);

            } catch (\Exception $e) {
                // Rollback transaction on error
                $em->rollback();
                $logger->error('Transaction rolled back due to error during profile deletion', [
                    'error' => $e->getMessage(),
                    'user' => $userEmail ?? 'unknown'
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            $logger->error('Error deleting profile: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            // If we get here and the error is about user refresh, try to handle it gracefully
            if (strpos($e->getMessage(), 'EntityUserProvider') !== false || 
                strpos($e->getMessage(), 'refresh a user') !== false) {
                
                // Clear session anyway and return success
                try {
                    $security->logout(false);
                    $session->invalidate();
                } catch (\Exception $sessionError) {
                    // Ignore session clearing errors at this point
                }
                
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Profile deletion completed. You will be redirected.',
                    'redirect' => '/farewell'
                ]);
            }
            
            return new JsonResponse([
                'error' => 'Failed to delete profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Farewell page - Shown after account deletion
     * GET /farewell - Shows goodbye message to deleted users
     */
    #[Route('/farewell', name: 'farewell', methods: ['GET'])]
    public function farewell(): Response
    {
        // This page doesn't require authentication since the user was just deleted
        return $this->render('farewell.html.twig');
    }
}
