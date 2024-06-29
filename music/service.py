# music/service.py
import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from config import SPOTIPY_CLIENT_ID, SPOTIPY_CLIENT_SECRET

class MusicService:
    def __init__(self):
        self.client_credentials_manager = SpotifyClientCredentials(
            client_id=SPOTIPY_CLIENT_ID,
            client_secret=SPOTIPY_CLIENT_SECRET
        )
        self.sp = spotipy.Spotify(client_credentials_manager=self.client_credentials_manager)

    def get_genres(self):
        return self.sp.recommendation_genre_seeds()['genres']

    def get_artists_by_genre(self, genre):
        # Recherche de playlists par genre
        results = self.sp.search(q=f'{genre}', type='playlist', limit=1)
        if results['playlists']['items']:
            playlist_id = results['playlists']['items'][0]['id']
            playlist_tracks = self.sp.playlist_tracks(playlist_id)
            artist_ids = []
            for item in playlist_tracks['items']:
                track = item['track']
                if track:
                    for artist in track['artists']:
                        artist_ids.append(artist['id'])
            # Suppression des doublons et limitation à 20 artistes
            artist_ids = list(set(artist_ids))[:20]
            artists = [self.sp.artist(artist_id) for artist_id in artist_ids]
            return artists
        return []

    def get_track_by_artist(self, artist_id):
        results = self.sp.artist_top_tracks(artist_id)
        if results['tracks']:
            return results['tracks'][0]
        return None
