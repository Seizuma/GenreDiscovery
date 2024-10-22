<?php

namespace App\Controller;

use App\Service\LastfmApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GenreController extends AbstractController
{
    private LastfmApiService $lastfmApiService;

    public function __construct(LastfmApiService $lastfmApiService)
    {
        $this->lastfmApiService = $lastfmApiService;
    }

    #[Route('/genres', name: 'genre_list')]
    public function listGenres(): Response
    {
        $genres = $this->lastfmApiService->fetchGenres();

        return $this->render('genre/index.html.twig', [
            'genres' => $genres,
        ]);
    }

    #[Route('/genre/{name}/artists', name: 'genre_artists')]
    public function listArtists(string $name): Response
    {
        $artists = $this->lastfmApiService->fetchArtistsByGenre($name);

        return $this->render('genre/artists.html.twig', [
            'genre' => $name,
            'artists' => $artists,
        ]);
    }

    #[Route('/artist/{name}/tracks', name: 'artist_tracks')]
    public function listTracks(string $name): Response
    {
        $tracks = $this->lastfmApiService->fetchTracksByArtist($name);

        return $this->render('artist/tracks.html.twig', [
            'artist' => $name,
            'tracks' => $tracks,
        ]);
    }
}
