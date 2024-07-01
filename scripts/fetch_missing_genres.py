# fetch_missing_genres.py
import sys
import os
import logging

# Ajouter le dossier parent au sys.path
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

from music.utils import initialize_spotify_client, initialize_database_session
from music.genres import GenreService

def fetch_missing_genres():
    logging.basicConfig(level=logging.DEBUG)
    logging.debug("Initialisation du client Spotify et de la session de la base de données.")
    
    sp = initialize_spotify_client()
    db = initialize_database_session()
    genre_service = GenreService(sp, db)

    # Load genres using the existing methods
    logging.debug("Chargement des genres initiaux.")
    genre_service.load_genres()
    
    # Fetch additional genres not covered by the initial method
    logging.debug("Récupération des genres supplémentaires.")
    genre_service.fetch_additional_genres()

if __name__ == "__main__":
    fetch_missing_genres()
