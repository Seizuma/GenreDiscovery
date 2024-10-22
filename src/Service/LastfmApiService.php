<?php

namespace App\Service;

use App\Entity\Genre;
use App\Entity\Artist;
use App\Entity\Track;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LastfmApiService
{
    private HttpClientInterface $client;
    private CacheInterface $cache;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private string $apiKey;
    private int $requestCount = 0;
    private float $startTime;

    public function __construct(
        HttpClientInterface $client,
        CacheInterface $cache,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        string $lastfmApiKey
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->apiKey = $lastfmApiKey;
        $this->startTime = microtime(true);
    }

    // --- Méthodes de Collecte (Utilisées par les Commandes Symfony) ---

    /**
     * Collecte tous les tags en combinant plusieurs sources.
     *
     * @return array
     */
    public function collectAllTags(): array
    {
        $allTags = [];
        $letters = array_slice(range('a', 'z'), 0, 5); // Limiter les lettres pour la performance
        $countries = ['United States', 'United Kingdom', 'France', 'Germany', 'Japan', 'Australia', 'Brazil', 'Canada', 'Russia', 'India'];

        $this->entityManager->beginTransaction();

            // Étape 1 : Parcourir les lettres pour rechercher des artistes
            foreach ($letters as $letter) {
                $this->logger->info(sprintf('Collecte des artistes pour la lettre "%s".', $letter));
                $artists = $this->searchArtistsByLetter($letter, 50);

                foreach ($artists as $artistData) {
                    $artistName = $artistData['name'];

                    // Vérifier si l'artiste existe déjà
                    $artist = $this->entityManager->getRepository(Artist::class)->find($artistName);
                    if (!$artist) {
                        $artist = new Artist();
                        $artist->setName($artistName);
                        $artist->setUrl($artistData['url'] ?? null);
                        $this->entityManager->persist($artist);
                    }

                    // Collecter les tags de l'artiste
                    $artistTags = $this->getArtistTopTags($artistName);
                    foreach ($artistTags as $tagData) {
                        $tagName = $tagData['name'];

                        // Vérifier si le genre existe déjà
                        $genre = $this->entityManager->getRepository(Genre::class)->find($tagName);
                        if (!$genre) {
                            $genre = new Genre();
                            $genre->setName($tagName);
                            $genre->setUrl($tagData['url'] ?? null);
                            $this->entityManager->persist($genre);
                        }

                        // Établir la relation
                        if (!$artist->getGenres()->contains($genre)) {
                            $artist->addGenre($genre);
                            $genre->addArtist($artist);
                        }

                        $allTags[$tagName] = $tagData;
                    }

                    // Flusher par batch pour améliorer la performance
                    if (($this->requestCount % 20) === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }
                    $this->requestCount++;
                }
            }

            // Étape 2 : Parcourir les pays pour obtenir les artistes populaires
            foreach ($countries as $country) {
                $this->logger->info(sprintf('Collecte des artistes pour le pays "%s".', $country));
                $artists = $this->getTopArtistsByCountry($country, 20);

                foreach ($artists as $artistData) {
                    $artistName = $artistData['name'];

                    // Vérifier si l'artiste existe déjà
                    $artist = $this->entityManager->getRepository(Artist::class)->find($artistName);
                    if (!$artist) {
                        $artist = new Artist();
                        $artist->setName($artistName);
                        $artist->setUrl($artistData['url'] ?? null);
                        $this->entityManager->persist($artist);
                    }

                    // Collecter les tags de l'artiste
                    $artistTags = $this->getArtistTopTags($artistName);
                    foreach ($artistTags as $tagData) {
                        $tagName = $tagData['name'];

                        // Vérifier si le genre existe déjà
                        $genre = $this->entityManager->getRepository(Genre::class)->find($tagName);
                        if (!$genre) {
                            $genre = new Genre();
                            $genre->setName($tagName);
                            $genre->setUrl($tagData['url'] ?? null);
                            $this->entityManager->persist($genre);
                        }

                        // Établir la relation
                        if (!$artist->getGenres()->contains($genre)) {
                            $artist->addGenre($genre);
                            $genre->addArtist($artist);
                        }

                        $allTags[$tagName] = $tagData;
                    }

                    // Flusher par batch pour améliorer la performance
                    if (($this->requestCount % 20) === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }
                    $this->requestCount++;
                }
            }

            // Étape 3 : Utiliser les tags similaires pour enrichir la liste
            $collectedTags = array_keys($allTags);
            foreach ($collectedTags as $tagName) {
                $this->logger->info(sprintf('Collecte des tags similaires pour le tag "%s".', $tagName));
                $similarTags = $this->getSimilarTags($tagName);
                foreach ($similarTags as $tagData) {
                    $tagNameSimilar = $tagData['name'];

                    // Vérifier si le genre existe déjà
                    $genre = $this->entityManager->getRepository(Genre::class)->find($tagNameSimilar);
                    if (!$genre) {
                        $genre = new Genre();
                        $genre->setName($tagNameSimilar);
                        $genre->setUrl($tagData['url'] ?? null);
                        $this->entityManager->persist($genre);
                    }

                    // Établir la relation
                    if (!$genre->getArtists()->isEmpty()) {
                        foreach ($genre->getArtists() as $artist) {
                            if (!$artist->getGenres()->contains($genre)) {
                                $artist->addGenre($genre);
                                $genre->addArtist($artist);
                            }
                        }
                    }

                    $allTags[$tagNameSimilar] = $tagData;

                    // Flusher par batch
                    if (($this->requestCount % 20) === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }
                    $this->requestCount++;
                }
            }

            // Flusher les enregistrements restants
            $this->entityManager->flush();
            $this->entityManager->commit();

            return array_values($allTags);
        }

        // --- Méthodes de Lecture (Utilisées par les Contrôleurs) ---

        /**
         * Récupère la liste des genres depuis le cache ou la base de données.
         *
         * @return array
         */
        public function getGenres(): array
        {
            return $this->cache->get('genres_list', function (ItemInterface $item) {
                $item->expiresAfter(3600); // 1 heure

                // Récupérer depuis la base de données
                $genres = $this->entityManager->getRepository(Genre::class)->findAll();

                return $genres;
            });
        }

        /**
         * Récupère les artistes associés à un genre donné depuis le cache ou la base de données.
         *
         * @param string $genreName
         * @return array
         */
        public function getArtistsByGenre(string $genreName): array
        {
            $cacheKey = 'artists_by_genre_' . md5($genreName);

            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($genreName) {
                $item->expiresAfter(3600); // 1 heure

                // Récupérer le genre depuis la base de données
                $genre = $this->entityManager->getRepository(Genre::class)->find($genreName);

                if ($genre && !$genre->getArtists()->isEmpty()) {
                    return $genre->getArtists()->toArray();
                }

                // Si le genre n'existe pas ou n'a pas d'artistes, retourner un tableau vide
                return [];
            });
        }

        /**
         * Récupère les pistes associées à un artiste donné depuis le cache ou la base de données.
         *
         * @param string $artistName
         * @return array
         */
        public function getTracksByArtist(string $artistName): array
        {
            $cacheKey = 'tracks_by_artist_' . md5($artistName);

            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($artistName) {
                $item->expiresAfter(3600); // 1 heure

                // Récupérer l'artiste depuis la base de données
                $artist = $this->entityManager->getRepository(Artist::class)->find($artistName);

                if ($artist && !$artist->getTracks()->isEmpty()) {
                    return $artist->getTracks()->toArray();
                }

                // Si l'artiste n'existe pas ou n'a pas de pistes, retourner un tableau vide
                return [];
            });
        }

        // --- Méthodes de Recherche (Utilisées uniquement par les Méthodes de Collecte) ---

        /**
         * Recherche des artistes commençant par une lettre donnée.
         *
         * @param string $letter
         * @param int $limit
         * @return array
         */
        public function searchArtistsByLetter(string $letter, int $limit = 100): array
        {
            $artists = [];
            $page = 1;
            $totalPages = 1;

            do {
                // Contrôle du rate limit
                $this->controlRateLimit();

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'artist.search',
                            'artist' => $letter . '*',
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                            'limit' => $limit,
                            'page' => $page,
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['results']['artistmatches']['artist'])) {
                        $artists = array_merge($artists, $data['results']['artistmatches']['artist']);
                    }

                    $totalResults = (int)$data['results']['opensearch:totalResults'];
                    $totalPages = ceil($totalResults / $limit);
                    $page++;
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la recherche des artistes par lettre "%s" : %s', $letter, $e->getMessage()));
                    break;
                }

            } while ($page <= $totalPages && $page <= 5); // Limiter à 5 pages pour éviter trop de requêtes

            return $artists;
        }

        /**
         * Recherche des tracks commençant par une lettre donnée.
         *
         * @param string $letter
         * @param int $limit
         * @return array
         */
        public function searchTracksByLetter(string $letter, int $limit = 100): array
        {
            $tracks = [];
            $page = 1;
            $totalPages = 1;

            do {
                // Contrôle du rate limit
                $this->controlRateLimit();

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'track.search',
                            'track' => $letter . '*',
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                            'limit' => $limit,
                            'page' => $page,
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['results']['trackmatches']['track'])) {
                        $tracks = array_merge($tracks, $data['results']['trackmatches']['track']);
                    }

                    $totalResults = (int)$data['results']['opensearch:totalResults'];
                    $totalPages = ceil($totalResults / $limit);
                    $page++;
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la recherche des tracks par lettre "%s" : %s', $letter, $e->getMessage()));
                    break;
                }

            } while ($page <= $totalPages && $page <= 5); // Limiter à 5 pages

            return $tracks;
        }

        /**
         * Récupère les top tags d'un artiste.
         *
         * @param string $artist
         * @return array
         */
        public function getArtistTopTags(string $artist): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            return $this->cache->get('artist_top_tags_' . md5($artist), function (ItemInterface $item) use ($artist) {
                $item->expiresAfter(3600);

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'artist.getTopTags',
                            'artist' => $artist,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['toptags']['tag'])) {
                        return $data['toptags']['tag'];
                    }
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la récupération des top tags pour l\'artiste "%s" : %s', $artist, $e->getMessage()));
                    return [];
                }

                return [];
            });
        }

        /**
         * Récupère les albums les plus populaires d'un artiste.
         *
         * @param string $artist
         * @param int $limit
         * @return array
         */
        public function getArtistTopAlbums(string $artist, int $limit = 5): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            return $this->cache->get('artist_top_albums_' . md5($artist), function (ItemInterface $item) use ($artist, $limit) {
                $item->expiresAfter(3600);

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'artist.getTopAlbums',
                            'artist' => $artist,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                            'limit' => $limit,
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['topalbums']['album'])) {
                        return $data['topalbums']['album'];
                    }
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la récupération des top albums pour l\'artiste "%s" : %s', $artist, $e->getMessage()));
                    return [];
                }

                return [];
            });
        }

        /**
         * Récupère les top tags d'un album.
         *
         * @param string $artist
         * @param string $album
         * @return array
         */
        public function getAlbumTopTags(string $artist, string $album): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            return $this->cache->get('album_top_tags_' . md5($artist . '_' . $album), function (ItemInterface $item) use ($artist, $album) {
                $item->expiresAfter(3600);

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'album.getTopTags',
                            'artist' => $artist,
                            'album' => $album,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['toptags']['tag'])) {
                        return $data['toptags']['tag'];
                    }
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la récupération des top tags pour l\'album "%s" de l\'artiste "%s" : %s', $album, $artist, $e->getMessage()));
                    return [];
                }

                return [];
            });
        }

        /**
         * Récupère les top tags d'un track.
         *
         * @param string $artist
         * @param string $track
         * @return array
         */
        public function getTrackTopTags(string $artist, string $track): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            return $this->cache->get('track_top_tags_' . md5($artist . '_' . $track), function (ItemInterface $item) use ($artist, $track) {
                $item->expiresAfter(3600);

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'track.getTopTags',
                            'artist' => $artist,
                            'track' => $track,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['toptags']['tag'])) {
                        return $data['toptags']['tag'];
                    }
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la récupération des top tags pour le track "%s" de l\'artiste "%s" : %s', $track, $artist, $e->getMessage()));
                    return [];
                }

                return [];
            });
        }

        /**
         * Récupère les tags similaires à un tag donné.
         *
         * @param string $tag
         * @return array
         */
        public function getSimilarTags(string $tag): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            return $this->cache->get('similar_tags_' . md5($tag), function (ItemInterface $item) use ($tag) {
                $item->expiresAfter(3600);

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'tag.getSimilar',
                            'tag' => $tag,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['similartags']['tag'])) {
                        return $data['similartags']['tag'];
                    }
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la récupération des tags similaires pour le tag "%s" : %s', $tag, $e->getMessage()));
                    return [];
                }

                return [];
            });
        }

        /**
         * Récupère les artistes les plus populaires d'un pays donné.
         *
         * @param string $country
         * @param int $limit
         * @return array
         */
        public function getTopArtistsByCountry(string $country, int $limit = 50): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            return $this->cache->get('top_artists_by_country_' . md5($country), function (ItemInterface $item) use ($country, $limit) {
                $item->expiresAfter(3600);

                try {
                    $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                        'query' => [
                            'method' => 'geo.getTopArtists',
                            'country' => $country,
                            'api_key' => $this->apiKey,
                            'format' => 'json',
                            'limit' => $limit,
                        ],
                    ]);

                    $data = $response->toArray();

                    if (isset($data['topartists']['artist'])) {
                        return $data['topartists']['artist'];
                    }
                } catch (\Exception $e) {
                    // Gérer l'exception
                    $this->logger->error(sprintf('Erreur lors de la récupération des top artistes pour le pays "%s" : %s', $country, $e->getMessage()));
                    return [];
                }

                return [];
            });
        }

        // --- Méthodes de Lecture Supplémentaires (si nécessaire) ---

        /**
         * Récupère les artistes associés à un genre donné depuis la base de données ou le cache.
         *
         * @param string $genreName
         * @return array
         */
        public function fetchArtistsByGenre(string $genreName): array
        {
            return $this->getArtistsByGenre($genreName);
        }

        /**
         * Récupère les genres depuis la base de données ou le cache.
         *
         * @return array
         */
        public function fetchGenres(): array
        {
            return $this->getGenres();
        }

        /**
         * Récupère les pistes associées à un artiste depuis la base de données ou le cache.
         *
         * @param string $artistName
         * @return array
         */
        public function fetchTracksByArtist(string $artistName): array
        {
            return $this->getTracksByArtist($artistName);
        }

        // --- Contrôle du Rate Limit ---

        /**
         * Contrôle le rate limit pour respecter la limite de 4 requêtes par seconde.
         */
        private function controlRateLimit()
        {
            $this->requestCount++;

            if ($this->requestCount >= 4) {
                $elapsedTime = microtime(true) - $this->startTime;

                if ($elapsedTime < 1) {
                    usleep((1 - $elapsedTime) * 1000000); // Pause pour respecter la limite
                }

                $this->requestCount = 0;
                $this->startTime = microtime(true);
            }
        }

        // --- Méthode Privée pour la Collecte des Artistes par Genre depuis l'API ---

        /**
         * Récupère les artistes associés à un genre donné depuis l'API Last.fm.
         *
         * @param string $genreName
         * @param int $limit
         * @return array
         */
        private function getArtistsByGenreFromApi(string $genreName, int $limit = 50): array
        {
            // Contrôle du rate limit
            $this->controlRateLimit();

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'tag.getTopArtists',
                        'tag' => $genreName,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                        'limit' => $limit,
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['topartists']['artist'])) {
                    return $data['topartists']['artist'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception (par exemple, journaliser l'erreur)
                $this->logger->error(sprintf('Erreur lors de la récupération des artistes pour le genre "%s" : %s', $genreName, $e->getMessage()));
                return [];
            }

            return [];
        }
    }

