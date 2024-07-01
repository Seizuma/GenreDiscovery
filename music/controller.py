# music/controller.py
from flask import Flask, render_template, jsonify
from music.service import MusicService
import logging

class MusicController:
    def __init__(self):
        self.app = Flask(__name__, static_folder='../static', template_folder='../templates')
        self.music_service = MusicService()
        self.setup_routes()

        # Empty the log file
        open('app.log', 'w').close()

        # Set up logging to file
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s %(levelname)s %(message)s',
            handlers=[
                logging.FileHandler("app.log"),
                logging.StreamHandler()
            ]
        )

        # Configure logging for spotipy and urllib3
        logging.getLogger('spotipy').setLevel(logging.WARNING)
        logging.getLogger('urllib3').setLevel(logging.WARNING)
        logging.getLogger('spotipy').addHandler(logging.FileHandler("app.log"))
        logging.getLogger('urllib3').addHandler(logging.FileHandler("app.log"))

    def setup_routes(self):
        """Set up the URL routes for the Flask application."""
        self.app.add_url_rule('/', 'index', self.index)
        self.app.add_url_rule('/artists/<genre>', 'artists', self.artists)
        self.app.add_url_rule('/play/<artist_id>', 'play_artist', self.play_artist)

    def index(self):
        """Render the index page with a list of genres."""
        try:
            genres = self.music_service.get_genres()
            logging.info(f"Genres: {genres}")
            return render_template('index.html', genres=genres)
        except Exception as e:
            logging.error(f"Error rendering index: {e}")
            return str(e)

    def artists(self, genre):
        """Render the artists page for a given genre."""
        try:
            artists = self.music_service.get_artists_by_genre(genre)
            return render_template('artists.html', genre=genre, artists=artists)
        except Exception as e:
            logging.error(f"Error rendering artists: {e}")
            return str(e)

    def play_artist(self, artist_id):
        """Fetch and play a track by artist ID."""
        try:
            track = self.music_service.get_track_by_artist(artist_id)
            if track:
                return jsonify(track={
                    "preview_url": track.get('preview_url'),
                    "external_url": track['external_urls']['spotify']
                })
            else:
                return jsonify(error="No tracks found for artist: " + artist_id)
        except Exception as e:
            logging.error(f"Error rendering play_artist: {e}")
            return jsonify(error=str(e))

    def run(self, debug=True):
        """Run the Flask application."""
        self.app.run(debug=debug)
