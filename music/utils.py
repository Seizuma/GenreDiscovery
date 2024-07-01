import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from config import SPOTIPY_CLIENT_ID, SPOTIPY_CLIENT_SECRET, DATABASE_URL

def initialize_spotify_client():
    client_credentials_manager = SpotifyClientCredentials(
        client_id=SPOTIPY_CLIENT_ID,
        client_secret=SPOTIPY_CLIENT_SECRET
    )
    sp = spotipy.Spotify(client_credentials_manager=client_credentials_manager)
    return sp

def initialize_database_session():
    engine = create_engine(DATABASE_URL)
    SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
    return SessionLocal()
