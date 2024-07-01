import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from sqlalchemy.orm import Session
from database import Genre
import logging
import random
import time

class GenreService:
    def __init__(self, sp, db: Session):
        self.sp = sp
        self.db = db

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

        # Retrieve genres from popular artists
        try:
            popular_artists = self.sp.search(q='year:2024', type='artist', limit=50)['artists']['items']
            for artist in popular_artists:
                artist_genres = artist['genres']
                genres.update(artist_genres)
                time.sleep(random.uniform(1, 2))  # Random delay between 1s and 2s
            logging.info(f"Genres from popular artists: {genres}")
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")

        # Store genres in the database
        for genre_name in genres:
            genre = Genre(name=genre_name)
            self.db.add(genre)
        self.db.commit()
        logging.info(f"Total genres stored in the database: {len(genres)}")

    def get_genres(self):
        """Retrieve all genres from the database."""
        genres = [genre.name for genre in self.db.query(Genre).all()]
        logging.info(f"Retrieved genres: {genres}")
        return genres

    def fetch_additional_genres(self):
        """Fetch additional genres from other sources and store them in the database."""
        genres = set()
        
        # Search for genres in popular playlists
        try:
            playlists = self.sp.search(q='genre', type='playlist', limit=10)['playlists']['items']
            logging.debug(f"Playlists populaires récupérées: {len(playlists)}")
            for playlist in playlists:
                playlist_id = playlist['id']
                playlist_tracks = self.sp.playlist_tracks(playlist_id, limit=50)['items']
                for track in playlist_tracks:
                    track_artists = track['track']['artists']
                    for artist in track_artists:
                        artist_info = self.sp.artist(artist['id'])
                        artist_genres = artist_info['genres']
                        genres.update(artist_genres)
                        logging.debug(f"Genres trouvés pour l'artiste {artist['name']}: {artist_genres}")
                        time.sleep(random.uniform(0.1, 0.5))  # Random delay between 0.1s and 0.5s
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException while fetching playlist genres: {e}")

        # Remove duplicates and already existing genres
        existing_genres = self.get_genres()
        new_genres = genres.difference(existing_genres)

        # Store new genres in the database
        logging.info("Storing new genres in the database...")
        for genre_name in new_genres:
            genre = Genre(name=genre_name)
            self.db.add(genre)
        self.db.commit()
        logging.info(f"Total new genres stored in the database: {len(new_genres)}")

    def get_artists_by_genre(self, genre):
        """Fetch artists by genre from Spotify."""
        logging.info(f"Fetching artists for genre: {genre}")
        artists = []
        offset = 0
        limit = 20

        while offset < 20:
            try:
                results = self.sp.search(q=f'genre:"{genre}"', type='artist', limit=limit, offset=offset, market="US")
                for artist in results['artists']['items']:
                    artist_info = self.sp.artist(artist['id'])
                    top_track = self.get_track_by_artist_and_genre(artist['id'], genre)
                    if top_track:  # Ensure a valid track is found
                        artist_data = {
                            'id': artist_info['id'],
                            'name': artist_info['name'],
                            'preview_url': top_track['preview_url'] if top_track else None,
                            'external_urls': top_track['external_urls'] if top_track else None,
                        }
                        artists.append(artist_data)
                        logging.info(f"Artist {artist_info['name']} with top track: {top_track}")
                offset += limit
            except spotipy.SpotifyException as e:
                if e.http_status == 429:
                    retry_after = int(e.headers.get('Retry-After', 60))
                    logging.error(f"Rate limited. Retrying after {retry_after} seconds")
                    time.sleep(retry_after)
                else:
                    logging.error(f"SpotifyException: {e}")
                    break

        logging.info(f"Artists found for genre {genre}: {artists}")
        return artists

    def get_track_by_artist_and_genre(self, artist_id, genre):
        """Fetch top tracks by artist from Spotify and ensure it belongs to the specified genre."""
        logging.info(f"Fetching top tracks for artist: {artist_id}")
        try:
            results = self.sp.artist_top_tracks(artist_id, market="US")
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
            if e.http_status == 429:
                retry_after = int(e.headers.get('Retry-After', 60))
                logging.error(f"Rate limited. Retrying after {retry_after} seconds")
                time.sleep(retry_after)
            return None
