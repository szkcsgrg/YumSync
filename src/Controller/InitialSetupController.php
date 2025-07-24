<?php
// src/Controller/InitialSetupController.php

namespace App\Controller;

use App\Entity\CreatedShops;
use App\Entity\User;
use App\Entity\Household;
use App\Entity\HouseholdUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling initial user setup process
 * This controller manages the first-time user configuration where users add their preferred stores
 */
class InitialSetupController extends AbstractController
{
   /**
    * Handle initial user setup - both displaying form and processing submission
    * GET /initialsetup - Shows the setup form
    * POST /initialsetup - Processes the submitted store data
    */
   #[Route('/initialsetup', name: 'initial_setup')]
    public function index(Request $request, EntityManagerInterface $em, Security $security, SessionInterface $session, LoggerInterface $logger)
    {
        // Log that the setup endpoint was accessed
        $logger->info('Initial setup endpoint called');
        
        // Get the currently authenticated user from Symfony security
        $googleUser = $security->getUser();
        $logger->info('Security user check:', ['user' => $googleUser ? $googleUser->getUserIdentifier() : 'null']);

        // Ensure user is authenticated - redirect to login if not
        if (!$googleUser) {
            $logger->error('User not authenticated, redirecting to login');
            throw $this->createAccessDeniedException("Nem vagy bejelentkezve.");
        }

        // Extract user information
        $email = $googleUser->getUserIdentifier(); // User's email address
        $name = $session->get('user_name'); // User's display name from session
        $userId = $googleUser->getUserIdentifier(); // Using email as user ID

        // Check if user already exists in database
        $userRepo = $em->getRepository(User::class);
        $existingUser = $userRepo->findOneBy(['email' => $email]);

        // If user already completed setup, redirect to main application
        if ($existingUser && $existingUser->isInitialSetupDone()) {
            return $this->redirectToRoute('application');
        }

        // Handle form submission (POST request) FIRST before checking pending status
        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            
            if ($action === 'create_household') {
                // Check if this is the form to show create household form or actually create it
                $householdName = trim($request->request->get('household_name', ''));
                
                if (empty($householdName)) {
                    // Show create household form
                    return $this->render('initial_setup/index.html.twig', [
                        'user' => $googleUser,
                        'email' => $email,
                        'name' => $name,
                        'step' => 'create_household'
                    ]);
                }
                
                // Actually create the household
                $householdRepo = $em->getRepository(Household::class);
                do {
                    $generatedId = rand(100000, 999999);
                } while ($householdRepo->findOneBy(['householdID' => $generatedId]));
                
                // Create household
                $household = new Household();
                $household->setHouseholdID($generatedId);
                $household->setName($householdName);
                $household->setOwnerUserId($userId);
                $em->persist($household);
                
                // Add user as owner
                $householdUser = new HouseholdUsers();
                $householdUser->setUserId($userId);
                $householdUser->setHouseholdId($generatedId);
                $householdUser->setRole('owner');
                $householdUser->setStatus('approved');
                $householdUser->setJoinedAt((new \DateTime())->format('Y-m-d H:i:s'));
                $em->persist($householdUser);
                
                // Save and continue to shop setup
                $em->flush();
                
                return $this->render('initial_setup/index.html.twig', [
                    'user' => $googleUser,
                    'email' => $email,
                    'name' => $name,
                    'step' => 'shop_setup',
                    'household_id' => $generatedId,
                    'household_name' => $householdName
                ]);
                
            } elseif ($action === 'join_household') {
                // Check if this is the form to show join household form or actually join it
                $householdId = trim($request->request->get('household_id', ''));
                
                if (empty($householdId)) {
                    // Show join household form
                    return $this->render('initial_setup/index.html.twig', [
                        'user' => $googleUser,
                        'email' => $email,
                        'name' => $name,
                        'step' => 'join_household'
                    ]);
                }
                
                // Actually join the household
                if (!preg_match('/^\d{6}$/', $householdId)) {
                    $this->addFlash('error', 'Please enter a valid 6-digit household ID');
                    return $this->render('initial_setup/index.html.twig', [
                        'user' => $googleUser,
                        'email' => $email,
                        'name' => $name,
                        'step' => 'join_household'
                    ]);
                }
                
                // Check if household exists
                $householdRepo = $em->getRepository(Household::class);
                $household = $householdRepo->findOneBy(['householdID' => (int)$householdId]);
                
                if (!$household) {
                    $this->addFlash('error', 'Household not found. Please check the ID and try again.');
                    return $this->render('initial_setup/index.html.twig', [
                        'user' => $googleUser,
                        'email' => $email,
                        'name' => $name,
                        'step' => 'join_household'
                    ]);
                }
                
                // Check if user is already a member
                $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
                $existingMembership = $householdUsersRepo->findOneBy([
                    'userId' => $userId,
                    'householdId' => (int)$householdId
                ]);
                
                if ($existingMembership) {
                    $this->addFlash('error', 'You are already a member of this household');
                    return $this->render('initial_setup/index.html.twig', [
                        'user' => $googleUser,
                        'email' => $email,
                        'name' => $name,
                        'step' => 'join_household'
                    ]);
                }
                
                // Create join request
                $householdUser = new HouseholdUsers();
                $householdUser->setUserId($userId);
                $householdUser->setHouseholdId((int)$householdId);
                $householdUser->setRole('member');
                $householdUser->setStatus('pending');
                $householdUser->setJoinedAt((new \DateTime())->format('Y-m-d H:i:s'));
                $em->persist($householdUser);
                
                // Create user account but DON'T mark setup as complete (they need approval first)
                if (!$existingUser) {
                    $newUser = new User();
                    $newUser->setEmail($email);
                    $newUser->setName($name);
                    $newUser->setInitialSetupDone(false); // Keep as false until approved
                    $newUser->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));
                    $em->persist($newUser);
                } else {
                    // Don't mark existing user as setup done
                    $existingUser->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));
                    $em->persist($existingUser);
                }
                
                $em->flush();
                
                // Add informative flash message and redirect to login
                $this->addFlash('info', 'Your join request has been sent to the household owner. Please be patient and come back later - all progress will be lost until the owner approves your request. You can log in again once approved.');
                return $this->redirectToRoute('app_login');
                
            } elseif ($action === 'setup_shops') {
                // Handle shop setup step (only for household creators)
                $storeNames = $request->request->all('stores');
                
                // Get the highest existing shopId across all users
                $shopsRepo = $em->getRepository(CreatedShops::class);
                $maxShopId = $shopsRepo->createQueryBuilder('s')
                    ->select('MAX(s.shopId)')
                    ->getQuery()
                    ->getSingleScalarResult();
                
                $shopCounter = $maxShopId ? $maxShopId + 1 : 1;

                // Process each submitted store name
                foreach ($storeNames as $storeName) {
                    $trimmed = trim($storeName);
                    if (!empty($trimmed)) {
                        $shop = new CreatedShops();
                        $shop->setShopId($shopCounter++);
                        $shop->setName($trimmed);
                        $shop->setUserId($userId);
                        $em->persist($shop);
                    }
                }

                // Complete user setup
                if (!$existingUser) {
                    $newUser = new User();
                    $newUser->setEmail($email);
                    $newUser->setName($name);
                    $newUser->setInitialSetupDone(true);
                    $newUser->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));
                    $em->persist($newUser);
                } else {
                    $existingUser->setInitialSetupDone(true);
                    $existingUser->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));
                    $em->persist($existingUser);
                }

                $em->flush();

                return $this->render('initial_setup/index.html.twig', [
                    'user' => $googleUser,
                    'email' => $email,
                    'name' => $name,
                    'step' => 'complete'
                ]);
                
            } elseif ($action === 'cancel_request') {
                // Cancel pending household join request
                $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
                $pendingRequest = $householdUsersRepo->findOneBy([
                    'userId' => $userId,
                    'status' => 'pending'
                ]);
                
                if ($pendingRequest) {
                    // Remove the pending request
                    $em->remove($pendingRequest);
                    $em->flush();
                    
                    $this->addFlash('success', 'Your household join request has been cancelled.');
                }
                
                // Show the default initial setup page
                return $this->render('initial_setup/index.html.twig', [
                    'user' => $googleUser,
                    'email' => $email,
                    'name' => $name,
                    'step' => 'household_choice'
                ]);
            }
        }

        // Check if user has a pending household join request (only for GET requests)
        if ($existingUser) {
            $householdUsersRepo = $em->getRepository(HouseholdUsers::class);
            $pendingRequest = $householdUsersRepo->findOneBy([
                'userId' => $userId,
                'status' => 'pending'
            ]);
            
            if ($pendingRequest) {
                // User has a pending request, show waiting message
                return $this->render('initial_setup/index.html.twig', [
                    'user' => $googleUser,
                    'email' => $email,
                    'name' => $name,
                    'step' => 'pending_approval',
                    'household_id' => $pendingRequest->getHouseholdId()
                ]);
            }
        }

        // Handle GET request - display the setup form
        return $this->render('initial_setup/index.html.twig', [
            'user' => $googleUser,    // Pass authenticated user object
            'email' => $email,        // Pass user email
            'name' => $name,          // Pass user display name
            'step' => 'household_choice'  // Default step for initial page load
        ]);
    }
}
