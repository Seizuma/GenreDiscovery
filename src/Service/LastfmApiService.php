<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LastfmApiService
{
    private HttpClientInterface $client;
    private string $apiKey;

    public function __construct(HttpClientInterface $client, string $lastfmApiKey)
    {
        $this->client = $client;
        $this->apiKey = $lastfmApiKey;
    }

    public function getGenres(): array
    {
        $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
            'query' => [
                'method' => 'tag.getTopTags',
                'api_key' => $this->apiKey,
                'format' => 'json',
            ],
        ]);

        $data = $response->toArray();

        // Vérifier si la réponse contient les tags
        if (isset($data['toptags']['tag'])) {
            return $data['toptags']['tag'];
        }

        // En cas d'erreur ou de données manquantes, retourner un tableau vide
        return [];
    }
}
