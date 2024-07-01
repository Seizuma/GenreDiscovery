# database.py
from sqlalchemy import Column, String, Integer, ForeignKey
from sqlalchemy.orm import relationship
from sqlalchemy.ext.declarative import declarative_base

Base = declarative_base()

class Genre(Base):
    __tablename__ = 'genres'
    id = Column(Integer, primary_key=True)
    name = Column(String, unique=True, nullable=False)
    artists = relationship('Artist', back_populates='genre')

class Artist(Base):
    __tablename__ = 'artists'
    id = Column(String, primary_key=True)
    name = Column(String, nullable=False)
    genre_id = Column(Integer, ForeignKey('genres.id'))
    preview_url = Column(String)
    external_urls = Column(String)
    genre = relationship('Genre', back_populates='artists')
