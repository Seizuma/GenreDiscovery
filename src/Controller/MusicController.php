<?php

namespace App\Controller;

use App\Service\LastfmApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MusicController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(LastfmApiService $lastfmApi): Response
    {
        $genres = $lastfmApi->getGenres();

        return $this->render('index.html.twig', [
            'genres' => $genres,
        ]);
    }
}
