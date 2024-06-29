# music/controller.py
from flask import Flask, render_template, jsonify
from music.service import MusicService
import logging
import os

class MusicController:
    def __init__(self):
        self.app = Flask(__name__, static_folder='../static', template_folder='../templates')
        self.music_service = MusicService()
        self.setup_routes()
        logging.basicConfig(level=logging.DEBUG)

    def setup_routes(self):
        self.app.add_url_rule('/', 'index', self.index)
        self.app.add_url_rule('/artists/<genre>', 'artists', self.artists)
        self.app.add_url_rule('/play/<artist_id>', 'play_artist', self.play_artist)

    def index(self):
        try:
            genres = self.music_service.get_genres()
            logging.debug(f"Genres: {genres}")
            return render_template('index.html', genres=genres)
        except Exception as e:
            logging.error(f"Error rendering index: {e}")
            return str(e)

    def artists(self, genre):
        try:
            artists = self.music_service.get_artists_by_genre(genre)
            return render_template('artists.html', genre=genre, artists=artists)
        except Exception as e:
            logging.error(f"Error rendering artists: {e}")
            return str(e)

    def play_artist(self, artist_id):
        try:
            track = self.music_service.get_track_by_artist(artist_id)
            if track:
                return jsonify(track={"preview_url": track['preview_url']})
            else:
                return jsonify(error="No tracks found for artist: " + artist_id)
        except Exception as e:
            logging.error(f"Error rendering play_artist: {e}")
            return jsonify(error=str(e))

    def run(self, debug=True):
        self.app.run(debug=debug)
