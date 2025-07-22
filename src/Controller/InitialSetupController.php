<?php
// src/Controller/InitialSetupController.php

namespace App\Controller;

use App\Entity\CreatedShops;
use App\Entity\User;
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

        // Handle form submission (POST request)
        if ($request->isMethod('POST')) {
            // Get all store names from the form submission
            // Form field name is 'stores' and it's an array of store names
            $storeNames = $request->request->all('stores');
            $shopCounter = 1; // Counter for assigning shop IDs

            // Process each submitted store name
            foreach ($storeNames as $storeName) {
                $trimmed = trim($storeName); // Remove whitespace
                if (!empty($trimmed)) { // Only create shops with non-empty names
                    // Create new shop entity
                    $shop = new CreatedShops();
                    $shop->setShopId($shopCounter++); // Assign incremental ID
                    $shop->setName($trimmed); // Set store name
                    $shop->setUserId($userId); // Associate with current user

                    // Mark for database insertion
                    $em->persist($shop);
                }
            }

            // Handle user creation or update
            if (!$existingUser) {
                // Create new user record if doesn't exist
                $newUser = new User();
                $newUser->setEmail($email);
                $newUser->setName($name);
                $newUser->setInitialSetupDone(true); // Mark setup as completed
                $newUser->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));

                $em->persist($newUser);
            } else {
                // Update existing user to mark setup as completed
                $existingUser->setInitialSetupDone(true);
                $existingUser->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));

                $em->persist($existingUser);
            }

            // Save all changes to database
            $em->flush();

            // Show success message to user
            $this->addFlash('success', 'Initial setup succesfully saved!');
            
            // Redirect to main application
            return $this->redirectToRoute('application');
        }

        // Handle GET request - display the setup form
        return $this->render('initial_setup/index.html.twig', [
            'user' => $googleUser,    // Pass authenticated user object
            'email' => $email,        // Pass user email
            'name' => $name,          // Pass user display name
        ]);
    }
}
