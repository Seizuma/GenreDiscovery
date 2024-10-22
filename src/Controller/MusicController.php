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
        $genres = $lastfmApi->collectAllTags();

        return $this->render('music/index.html.twig', [
            'genres' => $genres,
        ]);
    }
    
    #[Route('/genre/{genre}', name: 'genre_details')]
    public function genreDetails(string $genre, LastfmApiService $lastfmApi): Response
    {
        // Décoder le nom du genre
        $genre = urldecode($genre);

        $similarTags = $lastfmApi->getSimilarTags($genre);
        $artists = $lastfmApi->getArtistsByGenre($genre);

        return $this->render('music/genre.html.twig', [
            'genre' => $genre,
            'similarTags' => $similarTags,
            'artists' => $artists,
        ]);
    }

    #[Route('/artist/{artist}', name: 'artist_tracks')]
    public function artistTracks(string $artist, LastfmApiService $lastfmApi): Response
    {
        $artist = urldecode($artist);

        $tracks = $lastfmApi->getTopTracksByArtist($artist);

        return $this->render('music/artist.html.twig', [
            'artist' => $artist,
            'tracks' => $tracks,
        ]);
    }
}
