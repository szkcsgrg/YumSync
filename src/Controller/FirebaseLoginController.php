<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Controller for handling Firebase authentication and login processes
 * This controller manages user authentication via Firebase and integrates it with Symfony's security system
 */

final class FirebaseLoginController extends AbstractController
{
    /**
     * Display the login page
     * GET /login - Shows the Firebase login interface
     */
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function showLogin(): Response
    {
        return $this->render('login/index.html.twig', [
            'controller_name' => 'FirebaseLoginController',
            'firebase_config' => [
                'apiKey' => $_ENV['FIREBASE_API_KEY'],
                'authDomain' => $_ENV['FIREBASE_AUTH_DOMAIN'],
                'projectId' => $_ENV['FIREBASE_PROJECT_ID'],
                'storageBucket' => $_ENV['FIREBASE_STORAGE_BUCKET'],
                'messagingSenderId' => $_ENV['FIREBASE_MESSAGING_SENDER_ID'],
                'appId' => $_ENV['FIREBASE_APP_ID'],
                'measurementId' => $_ENV['FIREBASE_MEASUREMENT_ID']
            ]
        ]);
    }

    /**
     * Debug endpoint for testing logging functionality
     * GET /test-debug - Helps verify that logging and debugging tools work correctly
     */
    #[Route('/test-debug', name: 'test_debug', methods: ['GET'])]
    public function testDebug(LoggerInterface $logger): JsonResponse
    {
        // Test various debugging methods
        $logger->info('Test debug endpoint called');
        dump('This is a test dump'); // Shows in Symfony profiler
        error_log('This is a test error_log'); // Goes to PHP error log
        
        return new JsonResponse(['message' => 'Debug test complete - check logs and profiler']);
    }

    /**
     * Handle Firebase authentication and user login
     * POST /api/firebase-login - Processes Firebase ID token and authenticates user in Symfony
     * 
     * This method:
     * 1. Receives Firebase ID token from frontend
     * 2. Decodes and validates the token
     * 3. Creates or updates user in database
     * 4. Logs user into Symfony security system
     * 5. Determines redirect URL based on user setup status
     */
    #[Route('/api/firebase-login', name: 'firebase_login', methods: ['POST'])]
    public function firebaseLogin(Request $request, SessionInterface $session, LoggerInterface $logger, EntityManagerInterface $em, Security $security): JsonResponse
    {
        try {
            // Log the start of the authentication process
            $logger->info('Firebase login endpoint called');
            
            // Parse JSON data from the request body
            $data = json_decode($request->getContent(), true);
            $logger->info('Request data:', ['data' => $data]);
            
            // Debug output for development (visible in Symfony profiler)
            dump('ENDPOINT HIT: Firebase login called');
            dump('Request content:', $request->getContent());
            dump('Parsed data:', $data);
            
            // Extract Firebase ID token from request
            $idToken = $data['token'] ?? null;
            dump('Token extracted:', $idToken);
            $logger->info('ID Token received:', ['token_exists' => !empty($idToken)]);

            // Validate that token exists
            if (!$idToken) {
                $logger->error('Missing Firebase ID token');
                return new JsonResponse(['error' => 'Missing Firebase ID token'], 400);
            }

            // WARNING: This is a simplified token decoding for development purposes
            // In production, you should use proper Firebase token validation libraries
            // like kreait/firebase-tokentools or firebase/php-jwt
            $payload = explode('.', $idToken)[1]; // Get the payload part of JWT
            $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true); // Decode base64url
            $logger->info('Token decoded:', ['decoded' => $decoded]);

            // Extract user information from the decoded token
            $email = $decoded['email'] ?? null;
            $name = $decoded['name'] ?? 'User';
            $logger->info('Extracted user data:', ['email' => $email, 'name' => $name]);

            // Validate that email exists in token
            if (!$email) {
                $logger->error('Invalid token - no email found');
                return new JsonResponse(['error' => 'Invalid token'], 401);
            }

            // Store user data in session for later use
            $session->set('user_email', $email);
            $session->set('user_name', $name);
            $logger->info('Session data saved successfully');

            // Handle user creation or update in database
            $userRepo = $em->getRepository(User::class);
            $user = $userRepo->findOneBy(['email' => $email]);
            
            if (!$user) {
                // Create new user if doesn't exist
                $user = new User();
                $user->setEmail($email);
                $user->setName($name);
                $user->setInitialSetupDone(false); // New users need to complete setup
                $user->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));
                
                $em->persist($user);
                $em->flush();
                $logger->info('New user created');
            } else {
                // Update existing user's last login time
                $user->setLastlogin((new \DateTime())->format('Y-m-d H:i:s'));
                $em->persist($user);
                $em->flush();
                $logger->info('Existing user updated');
            }

            // Log the user into Symfony's security system
            // This is crucial for authentication to work properly
            $security->login($user);
            $logger->info('User logged in successfully');

            // Determine where to redirect user based on their setup status
            if (!$user->isInitialSetupDone()) {
                $redirectUrl = '/initialsetup'; // New users go to setup
            } else {
                $redirectUrl = '/application'; // Existing users go to main app
            }
            
            // Return successful response with redirect information
            $logger->info('Firebase login successful, returning response', ['redirect' => $redirectUrl]);
            return new JsonResponse(['message' => 'ok', 'redirect' => $redirectUrl]);
            
        } catch (\Exception $e) {
            // Handle any errors that occur during the process
            $logger->error('Exception in Firebase login: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }
}
