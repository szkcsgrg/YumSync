<?php

namespace App\Controller;

use App\Entity\Lists;
use App\Entity\CreatedShops;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

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

        // Get user's shops from database
        $shopsRepo = $em->getRepository(CreatedShops::class);
        $userShops = $shopsRepo->findBy(['userId' => $email]);

        // Get shopping lists grouped by shops
        $listsRepo = $em->getRepository(Lists::class);
        $shoppingLists = [];

        foreach ($userShops as $shop) {
            // Get all list items for this shop
            $shopLists = $listsRepo->findBy(['shopId' => $shop->getShopId()]);
            
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
            'shops_count' => count($userShops),
            'lists_count' => count($shoppingLists)
        ]);

        return $this->render('application/index.html.twig', [
            'name' => $name,
            'email' => $email,
            'userShops' => $userShops,
            'shoppingLists' => $shoppingLists
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

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

            // Verify shop belongs to user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $shopId, 'userId' => $user->getUserIdentifier()]);
            
            if (!$shop) {
                return new JsonResponse(['error' => 'Shop not found or not accessible'], 404);
            }

            // Create new list item
            $listItem = new Lists();
            $listItem->setListId(1); // For now, using default list ID
            $listItem->setHouseholdId(1); // For now, using default household ID
            $listItem->setShopId($shopId);
            $listItem->setItemId(0); // For now, using 0 as we don't have item catalog yet
            $listItem->setQuantity($quantity);
            $listItem->setLabel($productName);

            $em->persist($listItem);
            $em->flush();

            $logger->info('Product added to shopping list', [
                'user' => $user->getUserIdentifier(),
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Verify the shop belongs to the user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $listItem->getShopId(), 'userId' => $user->getUserIdentifier()]);

            if (!$shop) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Mark as purchased
            $listItem->setPurchased(true);
            $em->flush();

            $logger->info('Product marked as purchased in shopping list', [
                'user' => $user->getUserIdentifier(),
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Verify the shop belongs to the user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $listItem->getShopId(), 'userId' => $user->getUserIdentifier()]);

            if (!$shop) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Mark as unpurchased
            $listItem->setPurchased(false);
            $em->flush();

            $logger->info('Product marked as unpurchased in shopping list', [
                'user' => $user->getUserIdentifier(),
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            // Find the list item
            $listsRepo = $em->getRepository(Lists::class);
            $listItem = $listsRepo->find($id);

            if (!$listItem) {
                return new JsonResponse(['error' => 'List item not found'], 404);
            }

            // Verify the shop belongs to the user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $listItem->getShopId(), 'userId' => $user->getUserIdentifier()]);

            if (!$shop) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Remove the item
            $em->remove($listItem);
            $em->flush();

            $logger->info('Product removed from shopping list', [
                'user' => $user->getUserIdentifier(),
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

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

            // Verify the shop belongs to the user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $listItem->getShopId(), 'userId' => $user->getUserIdentifier()]);

            if (!$shop) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Verify the new shop also belongs to the user (if shop is being changed)
            $newShop = null;
            if ($shopId !== $listItem->getShopId()) {
                $newShop = $shopsRepo->findOneBy(['shopId' => $shopId, 'userId' => $user->getUserIdentifier()]);
                if (!$newShop) {
                    return new JsonResponse(['error' => 'New shop not found or not accessible'], 404);
                }
            } else {
                // If shop didn't change, use the existing shop
                $newShop = $shop;
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
                'user' => $user->getUserIdentifier(),
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            // Verify the shop belongs to the user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $shopId, 'userId' => $user->getUserIdentifier()]);

            if (!$shop) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Find all purchased items for this shop
            $listsRepo = $em->getRepository(Lists::class);
            $purchasedItems = $listsRepo->findBy(['shopId' => $shopId, 'purchased' => true]);

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
                'user' => $user->getUserIdentifier(),
                'shopId' => $shopId,
                'shopName' => $shop->getName(),
                'deletedCount' => $deletedCount
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Deleted {$deletedCount} purchased items from {$shop->getName()}"
            ]);

        } catch (\Exception $e) {
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
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            // Verify the shop belongs to the user
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $shop = $shopsRepo->findOneBy(['shopId' => $shopId, 'userId' => $user->getUserIdentifier()]);

            if (!$shop) {
                return new JsonResponse(['error' => 'Access denied'], 403);
            }

            // Find all purchased items for this shop
            $listsRepo = $em->getRepository(Lists::class);
            $purchasedItems = $listsRepo->findBy(['shopId' => $shopId, 'purchased' => true]);

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
                'user' => $user->getUserIdentifier(),
                'shopId' => $shopId,
                'shopName' => $shop->getName(),
                'revertedCount' => $revertedCount
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Moved {$revertedCount} items from purchased back to {$shop->getName()} shopping list"
            ]);

        } catch (\Exception $e) {
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
            
            // Get authenticated user
            $user = $security->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'Authentication required'], 401);
            }

            $email = $user->getUserIdentifier();
            
            // Parse request data
            $data = json_decode($request->getContent(), true);
            $itemId = $data['itemId'] ?? null;
            $targetShopId = $data['targetShopId'] ?? null;
            $isPurchased = $data['isPurchased'] ?? false;

            $logger->info('Move item data:', [
                'itemId' => $itemId,
                'targetShopId' => $targetShopId,
                'isPurchased' => $isPurchased
            ]);

            // Validate input
            if (!$itemId || !$targetShopId) {
                return new JsonResponse(['error' => 'Missing itemId or targetShopId'], 400);
            }

            // Get the item from database
            $listsRepo = $em->getRepository(Lists::class);
            $item = $listsRepo->find($itemId);

            if (!$item) {
                return new JsonResponse(['error' => 'Item not found'], 404);
            }

            // Verify user owns this item (by checking if they own the shop the item is in)
            $shopsRepo = $em->getRepository(CreatedShops::class);
            $currentShop = $shopsRepo->findOneBy(['shopId' => $item->getShopId(), 'userId' => $email]);

            if (!$currentShop) {
                return new JsonResponse(['error' => 'Unauthorized - you do not own this item'], 403);
            }

            // Verify target shop exists and user owns it
            $targetShop = $shopsRepo->findOneBy(['shopId' => $targetShopId, 'userId' => $email]);

            if (!$targetShop) {
                return new JsonResponse(['error' => 'Target shop not found or unauthorized'], 404);
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
                'isPurchased' => $isPurchased
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Item moved successfully'
            ]);

        } catch (\Exception $e) {
            $logger->error('Error moving item: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }
}
