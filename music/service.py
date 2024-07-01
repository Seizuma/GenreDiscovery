# service.py
import spotipy
from database import Artist, Genre
import logging
import time
from sqlalchemy.orm import Session
from music.utils import initialize_spotify_client, initialize_database_session

class MusicService:
    def __init__(self):
        self.sp = initialize_spotify_client()
        self.db = initialize_database_session()

    def load_genres(self):
        """Load genres from Spotify and store in the database."""
        genres = self.sp.recommendation_genre_seeds()['genres']
        for genre_name in genres:
            genre = Genre(name=genre_name)
            self.db.add(genre)
        self.db.commit()
        logging.info("Genres loaded and stored in the database.")

    def get_genres(self):
        """Retrieve all genres from the database."""
        genres = self.db.query(Genre).all()
        return [genre.name for genre in genres]

    def get_artists_by_genre(self, genre_name):
        """Fetch artists by genre from Spotify and store in the database if not already present."""
        genre = self.db.query(Genre).filter_by(name=genre_name).first()
        if not genre:
            logging.error(f"Genre {genre_name} not found in the database.")
            return []

        if not genre.artists:
            logging.info(f"Fetching artists for genre: {genre_name}")
            artists = []
            offset = 0
            limit = 20

            while offset < 100:  # Adjust the maximum number of artists to fetch per genre if necessary
                try:
                    results = self.sp.search(q=f'genre:"{genre_name}"', type='artist', limit=limit, offset=offset)
                    for artist in results['artists']['items']:
                        artist_info = self.sp.artist(artist['id'])
                        top_track = self.get_track_by_artist(artist['id'])
                        if top_track:  # Ensure a valid track is found
                            artist_data = {
                                'id': artist_info['id'],
                                'name': artist_info['name'],
                                'preview_url': top_track['preview_url'] if top_track else None,
                                'external_urls': top_track['external_urls'] if top_track else None,
                            }
                            artists.append(artist_data)
                            # Save artist to the database
                            artist_db = Artist(
                                id=artist_info['id'],
                                name=artist_info['name'],
                                genre_id=genre.id,
                                preview_url=top_track['preview_url'] if top_track else None,
                                external_urls=top_track['external_urls'] if top_track else None,
                            )
                            self.db.add(artist_db)
                            self.db.commit()
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

            logging.info(f"Artists found for genre {genre_name}: {artists}")
            return artists
        else:
            logging.info(f"Artists for genre {genre_name} loaded from database.")
            return [{'id': artist.id, 'name': artist.name, 'preview_url': artist.preview_url, 'external_urls': artist.external_urls} for artist in genre.artists]

    def get_track_by_artist(self, artist_id):
        """Fetch top tracks by artist from Spotify."""
        logging.info(f"Fetching top tracks for artist: {artist_id}")
        try:
            results = self.sp.artist_top_tracks(artist_id)
            for track in results['tracks']:
                if track['preview_url']:
                    return track
            logging.info("No track with preview found, returning first track")
            return results['tracks'][0] if results['tracks'] else None
        except spotipy.SpotifyException as e:
            logging.error(f"SpotifyException: {e}")
            if e.http_status == 429:
                retry_after = int(e.headers.get('Retry-After', 60))
                logging.error(f"Rate limited. Retrying after {retry_after} seconds")
                time.sleep(retry_after)
            return None
