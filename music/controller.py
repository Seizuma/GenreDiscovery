from flask import Flask, render_template
from music.service import MusicService
import logging
import os

class MusicController:
    def __init__(self):
        # Spécifiez explicitement le répertoire des templates
        self.app = Flask(__name__,static_folder='../static', template_folder='../templates')
        self.music_service = MusicService()
        self.setup_routes()
        logging.basicConfig(level=logging.DEBUG)

    def setup_routes(self):
        self.app.add_url_rule('/', 'index', self.index)
        self.app.add_url_rule('/play/<genre>', 'play_genre', self.play_genre)

    def index(self):
        try:
            genres = self.music_service.get_genres()
            logging.debug(f"Genres: {genres}")
            return render_template('index.html', genres=genres)
        except Exception as e:
            logging.error(f"Error rendering index: {e}")
            return str(e)

    def play_genre(self, genre):
        try:
            track = self.music_service.get_track_by_genre(genre)
            if track:
                return render_template('play.html', track=track)
            else:
                return "No tracks found for genre: " + genre
        except Exception as e:
            logging.error(f"Error rendering play_genre: {e}")
            return str(e)

    def run(self, debug=True):
        self.app.run(debug=debug)
