<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LandingScreenController extends AbstractController
{
    #[Route('/', name: 'app_landing_screen')]
    public function index(): Response
    {
        return $this->render('landing_screen/index.html.twig', [
            'controller_name' => 'LandingScreenController',
        ]);
    }
}
