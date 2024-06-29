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

    def get_track_by_genre(self, genre):
        results = self.sp.search(q='genre:' + genre, type='track', limit=1)
        if results['tracks']['items']:
            return results['tracks']['items'][0]
        return None
