# music/controller.py
from flask import Flask, render_template
from service import MusicService

class MusicController:
    def __init__(self):
        self.app = Flask(__name__)
        self.music_service = MusicService()
        self.setup_routes()

    def setup_routes(self):
        self.app.add_url_rule('/', 'index', self.index)
        self.app.add_url_rule('/play/<genre>', 'play_genre', self.play_genre)

    def index(self):
        genres = self.music_service.get_genres()
        return render_template('index.html', genres=genres)

    def play_genre(self, genre):
        track = self.music_service.get_track_by_genre(genre)
        if track:
            return render_template('play.html', track=track)
        else:
            return "No tracks found for genre: " + genre

    def run(self, debug=True):
        self.app.run(debug=debug)
