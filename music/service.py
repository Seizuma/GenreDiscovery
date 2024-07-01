# music/service.py
import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from config import SPOTIPY_CLIENT_ID, SPOTIPY_CLIENT_SECRET
from sqlalchemy.orm import Session
from database import SessionLocal, Genre
import logging
import time

class MusicService:
    def __init__(self):
        self.client_credentials_manager = SpotifyClientCredentials(
            client_id=SPOTIPY_CLIENT_ID,
            client_secret=SPOTIPY_CLIENT_SECRET
        )
        self.sp = spotipy.Spotify(client_credentials_manager=self.client_credentials_manager)
        logging.info("Spotify client initialized")

        # Initialize the database session
        self.db: Session = SessionLocal()

        # Preload genres into the database if empty
        self.load_genres()

    def load_genres(self):
        """Load genres into the database if not already present."""
        if self.db.query(Genre).count() > 0:
            logging.info("Genres already loaded in the database")
            return

        logging.info("Fetching available genre seeds")
        genres = set()

        # Retrieve genres from recommendation seeds
        try:
            genre_seeds = self.sp.recommendation_genre_seeds()['genres']
            genres.update(genre_seeds)
            logging.info(f"Genres from recommendation seeds: {genre_seeds}")
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")
            time.sleep(60)  # Wait for a minute if rate limited

        # Retrieve genres from popular artists
        try:
            popular_artists = self.sp.search(q='year:2024', type='artist', limit=50)['artists']['items']
            for artist in popular_artists:
                artist_genres = artist['genres']
                genres.update(artist_genres)
            logging.info(f"Genres from popular artists: {genres}")
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")
            time.sleep(60)  # Wait for a minute if rate limited

        # Retrieve genres from popular playlists
        try:
            popular_playlists = self.sp.search(q='genre', type='playlist', limit=50)['playlists']['items']
            for playlist in popular_playlists:
                playlist_id = playlist['id']
                playlist_tracks = self.sp.playlist_tracks(playlist_id)
                for item in playlist_tracks['items']:
                    if 'track' in item and item['track'] and 'artists' in item['track']:
                        track = item['track']
                        for artist in track['artists']:
                            artist_info = self.sp.artist(artist['id'])
                            artist_genres = artist_info['genres']
                            genres.update(artist_genres)
            logging.info(f"Genres from popular playlists: {genres}")
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")
            time.sleep(60)  # Wait for a minute if rate limited

        # Store genres in the database
        for genre_name in genres:
            genre = Genre(name=genre_name)
            self.db.add(genre)
        self.db.commit()
        logging.info(f"Total genres stored in the database: {len(genres)}")

    def get_genres(self):
        """Retrieve all genres from the database."""
        return [genre.name for genre in self.db.query(Genre).all()]

    def get_artists_by_genre(self, genre):
        """Fetch artists by genre from Spotify."""
        logging.info(f"Fetching artists for genre: {genre}")
        artists = []
        offset = 0
        limit = 20

        try:
            results = self.sp.search(q=f'genre:"{genre}"', type='artist', limit=limit, offset=offset)
            for artist in results['artists']['items']:
                artist_info = self.sp.artist(artist['id'])
                if genre in artist_info['genres']:
                    artists.append(artist_info)

        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")
            time.sleep(60)  # Wait for a minute if rate limited

        logging.info(f"Artists found for genre {genre}: {artists}")
        return artists

    def get_track_by_artist(self, artist_id):
        """Fetch top tracks by artist from Spotify."""
        logging.info(f"Fetching top tracks for artist: {artist_id}")
        try:
            results = self.sp.artist_top_tracks(artist_id)
            if results['tracks']:
                for track in results['tracks']:
                    if track['preview_url']:
                        logging.info(f"Track with preview found: {track['name']}")
                        return track
                logging.info("No track with preview found, returning first track")
                return results['tracks'][0] if results['tracks'] else None
            logging.info("No tracks found for artist")
            return None
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")
            time.sleep(60)  # Wait for a minute if rate limited
            return None
