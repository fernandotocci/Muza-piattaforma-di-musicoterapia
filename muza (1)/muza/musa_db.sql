-- Database Mūza - Versione completa con API musicali e supporto playlist nelle sessioni
-- AGGIORNATO per supportare playlist nelle sessioni di ascolto

-- Usa il database esistente
USE musa_db;

-- Elimina le tabelle esistenti nell'ordine corretto (per le foreign key)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS track_ratings;
DROP TABLE IF EXISTS listening_sessions;
DROP TABLE IF EXISTS playlist_tracks;
DROP TABLE IF EXISTS therapist_playlists;
DROP TABLE IF EXISTS user_music_tokens;
DROP TABLE IF EXISTS patient_goals;
DROP TABLE IF EXISTS secure_messages;
DROP TABLE IF EXISTS tracks;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- CREAZIONE TABELLE
-- =====================================================

-- Tabella users con supporto API musicali
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  first_name VARCHAR(50) NOT NULL,
  last_name VARCHAR(50) NOT NULL,
  user_type ENUM('admin', 'therapist', 'patient') NOT NULL,
  last_login TIMESTAMP NULL,
  therapist_id INT NULL COMMENT 'ID del terapeuta assegnato (solo per pazienti)',
  profile_notes TEXT NULL COMMENT 'Note del profilo',
  -- Campi per API musicali
  spotify_id VARCHAR(255) NULL COMMENT 'ID utente Spotify',
  spotify_display_name VARCHAR(255) NULL COMMENT 'Nome display Spotify',
  spotify_image VARCHAR(500) NULL COMMENT 'Immagine profilo Spotify',
  youtube_channel_id VARCHAR(255) NULL COMMENT 'ID canale YouTube',
  apple_music_id VARCHAR(255) NULL COMMENT 'ID Apple Music',
  music_preferences TEXT NULL COMMENT 'Preferenze musicali JSON',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabella per i token delle API musicali
CREATE TABLE user_music_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  service ENUM('spotify', 'youtube', 'apple', 'deezer', 'soundcloud') NOT NULL,
  access_token TEXT NOT NULL,
  refresh_token TEXT NULL,
  expires_at TIMESTAMP NULL,
  scope VARCHAR(500) NULL COMMENT 'Permessi ottenuti',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_service (user_id, service),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabella tracks (aggiornata con supporto streaming)
CREATE TABLE tracks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  artist VARCHAR(255) NOT NULL,
  album VARCHAR(255) NULL COMMENT 'Nome album',
  duration INT NOT NULL COMMENT 'Durata in secondi',
  file_path VARCHAR(255) NULL COMMENT 'File locale (opzionale)',
  -- Supporto streaming
  spotify_track_id VARCHAR(255) NULL COMMENT 'ID Spotify',
  spotify_url VARCHAR(500) NULL COMMENT 'URL Spotify del brano',
  preview_url VARCHAR(500) NULL COMMENT 'URL anteprima del brano',
  image_url VARCHAR(500) NULL COMMENT 'URL immagine cover',
  youtube_video_id VARCHAR(255) NULL COMMENT 'ID YouTube',
  apple_music_id VARCHAR(255) NULL COMMENT 'ID Apple Music',
  stream_url VARCHAR(500) NULL COMMENT 'URL streaming diretto',
  -- Metadati
  category VARCHAR(100) NULL COMMENT 'Categoria musicale',
  mood_target VARCHAR(100) NULL COMMENT 'Umore target del brano',
  therapeutic_focus VARCHAR(100) NULL COMMENT 'Focus terapeutico',
  bpm INT NULL COMMENT 'Battiti per minuto',
  energy_level ENUM('low', 'medium', 'high') NULL COMMENT 'Livello energetico',
  valence DECIMAL(3,2) NULL COMMENT 'Valenza emotiva (0-1)',
  popularity INT NULL COMMENT 'Popolarità Spotify (0-100)',
  explicit BOOLEAN DEFAULT FALSE COMMENT 'Contenuto esplicito',
  therapist_id INT NULL COMMENT 'ID terapeuta che ha aggiunto il brano',
  source ENUM('local', 'spotify', 'youtube', 'apple') DEFAULT 'local' COMMENT 'Fonte del brano',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_spotify_track_id (spotify_track_id),
  INDEX idx_therapist_id (therapist_id),
  FOREIGN KEY (therapist_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabella per le playlist personalizzate dei terapeuti
CREATE TABLE therapist_playlists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  therapist_id INT NOT NULL,
  patient_id INT NULL COMMENT 'Paziente assegnato (NULL se playlist non ancora assegnata)',
  name VARCHAR(255) NOT NULL COMMENT 'Nome della playlist',
  description TEXT NULL,
  goal_focus VARCHAR(100) NULL COMMENT 'Focus terapeutico',
  -- Collegamento streaming
  spotify_playlist_id VARCHAR(255) NULL COMMENT 'ID playlist Spotify',
  youtube_playlist_id VARCHAR(255) NULL COMMENT 'ID playlist YouTube',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (therapist_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabella per associare brani alle playlist
CREATE TABLE playlist_tracks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT NOT NULL,
  track_id INT NOT NULL,
  position INT DEFAULT 1 COMMENT 'Posizione del brano nella playlist',
  therapist_notes TEXT NULL COMMENT 'Note del terapeuta per questo brano',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_playlist_track (playlist_id, track_id),
  FOREIGN KEY (playlist_id) REFERENCES therapist_playlists(id) ON DELETE CASCADE,
  FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabella per le sessioni di ascolto - AGGIORNATA per supportare playlist
CREATE TABLE listening_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  track_id INT NULL,
  playlist_id INT NULL COMMENT 'ID playlist se sessione playlist',
  session_type ENUM('single_track', 'playlist') DEFAULT 'single_track' COMMENT 'Tipo di sessione',
  mood_before INT NULL COMMENT 'Umore prima (1-10)',
  mood_after INT NULL COMMENT 'Umore dopo (1-10)',
  energy_before INT NULL COMMENT 'Energia prima (1-10)',
  energy_after INT NULL COMMENT 'Energia dopo (1-10)',
  listen_duration INT DEFAULT 0 COMMENT 'Durata ascolto in secondi',
  completed BOOLEAN DEFAULT FALSE COMMENT 'Sessione completata',
  notes TEXT NULL COMMENT 'Note del paziente',
  -- Metadati streaming
  source_service ENUM('local', 'spotify', 'youtube', 'apple') DEFAULT 'local',
  streaming_quality VARCHAR(50) NULL COMMENT 'Qualità streaming',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  FOREIGN KEY (playlist_id) REFERENCES therapist_playlists(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabella per le valutazioni dei brani - AGGIORNATA per supportare playlist e sessioni
CREATE TABLE track_ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  track_id INT NOT NULL,
  rating INT NOT NULL COMMENT 'Valutazione 1-5 stelle',
  helpful BOOLEAN NULL COMMENT 'Il brano è stato utile?',
  emotional_impact VARCHAR(100) NULL COMMENT 'Impatto emotivo',
  feedback TEXT NULL COMMENT 'Feedback testuale del paziente',
  playlist_id INT NULL COMMENT 'ID playlist se valutazione durante sessione playlist',
  session_id INT NULL COMMENT 'ID sessione di ascolto',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_track (user_id, track_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
  FOREIGN KEY (playlist_id) REFERENCES therapist_playlists(id) ON DELETE SET NULL,
  FOREIGN KEY (session_id) REFERENCES listening_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabella per gli obiettivi dei pazienti
CREATE TABLE patient_goals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  goal_type ENUM('mood_improvement', 'stress_reduction', 'sleep_quality', 'energy_boost', 'custom') NOT NULL,
  goal_description TEXT NOT NULL,
  target_value INT NULL COMMENT 'Valore target (1-10)',
  current_progress INT DEFAULT 0 COMMENT 'Progresso attuale',
  weekly_target INT DEFAULT 3 COMMENT 'Sessioni target per settimana',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status ENUM('active', 'completed', 'paused') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabella per i messaggi sicuri terapeuta-paziente
CREATE TABLE secure_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  recipient_id INT NOT NULL,
  subject VARCHAR(255) NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN DEFAULT FALSE,
  message_type ENUM('general', 'progress_update', 'concern', 'appointment') DEFAULT 'general',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Aggiungi la foreign key per therapist_id dopo aver creato la tabella users
ALTER TABLE users ADD CONSTRAINT fk_therapist FOREIGN KEY (therapist_id) REFERENCES users(id) ON DELETE SET NULL;

-- Aggiungi foreign key per therapist_id nella tabella tracks
ALTER TABLE tracks ADD CONSTRAINT fk_tracks_therapist FOREIGN KEY (therapist_id) REFERENCES users(id) ON DELETE SET NULL;

-- =====================================================
-- INDICI PER PERFORMANCE
-- =====================================================

CREATE INDEX idx_sessions_user_date ON listening_sessions(user_id, created_at);
CREATE INDEX idx_ratings_track ON track_ratings(track_id);
CREATE INDEX idx_messages_recipient ON secure_messages(recipient_id, is_read);
CREATE INDEX idx_goals_user_status ON patient_goals(user_id, status);
CREATE INDEX idx_tokens_user_service ON user_music_tokens(user_id, service);
CREATE INDEX idx_tracks_streaming ON tracks(spotify_track_id, youtube_video_id);
CREATE INDEX idx_tracks_spotify_id ON tracks(spotify_track_id);
CREATE INDEX idx_tracks_therapist ON tracks(therapist_id);
CREATE INDEX idx_tracks_source ON tracks(source);
CREATE INDEX idx_playlists_therapist ON therapist_playlists(therapist_id);
CREATE INDEX idx_playlists_patient ON therapist_playlists(patient_id);
CREATE INDEX idx_playlist_tracks_playlist ON playlist_tracks(playlist_id);
CREATE INDEX idx_playlist_tracks_track ON playlist_tracks(track_id);

-- NUOVI INDICI PER PLAYLIST NELLE SESSIONI
CREATE INDEX idx_listening_sessions_playlist ON listening_sessions(playlist_id);
CREATE INDEX idx_track_ratings_playlist ON track_ratings(playlist_id);
CREATE INDEX idx_track_ratings_session ON track_ratings(session_id);
CREATE INDEX idx_sessions_type ON listening_sessions(session_type);

-- =====================================================
-- INSERIMENTO DATI DI TEST - SOLO TERAPEUTA
-- =====================================================

-- Inserimento del terapeuta Mario Rossi
INSERT INTO users (
    username, 
    first_name, 
    last_name, 
    email, 
    password, 
    user_type, 
    created_at
) VALUES (
    'mario.rossi',
    'Mario',
    'Rossi',
    'mario.rossi@muza.it',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Password: password
    'therapist',
    NOW()
);

-- =====================================================
-- MESSAGGIO FINALE DI SUCCESSO
-- =====================================================

SELECT '
==============================================
✅ DATABASE MŪZA COMPLETO E AGGIORNATO!
==============================================

🔑 CREDENZIALI DI ACCESSO:

TERAPEUTA:
  📧 Email: mario.rossi@muza.it  
  🔑 Password: password

🎭 FUNZIONALITÀ PLAYLIST NELLE SESSIONI:
  ✅ listening_sessions.playlist_id (supporto playlist)
  ✅ listening_sessions.session_type (tipo sessione)
  ✅ track_ratings.feedback (feedback testuale)
  ✅ track_ratings.playlist_id (collegamento playlist)
  ✅ track_ratings.session_id (collegamento sessione)
  ✅ track_ratings.updated_at (timestamp aggiornamento)

📊 STRUTTURA COMPLETA:
  ✅ 1 Terapeuta di base (Mario Rossi)
  ✅ Sistema di playlist per terapeuti
  ✅ Gestione sessioni di ascolto avanzate
  ✅ Valutazioni dettagliate per singoli brani
  ✅ Supporto completo API Spotify
  ✅ Tracking progresso nelle playlist

🎵 WORKFLOW PLAYLIST COMPLETO:
  ✅ Terapeuta crea playlist per pazienti
  ✅ Paziente vede playlist assegnate
  ✅ Navigazione tra brani della playlist
  ✅ Valutazione per ogni singolo brano
  ✅ Valutazione finale della sessione completa
  ✅ Note del terapeuta per ogni brano

🔗 FOREIGN KEYS E INDICI:
  ✅ Tutti i collegamenti tra tabelle configurati
  ✅ Indici per performance ottimizzata
  ✅ Supporto CASCADE per pulizia dati

Il sistema è pronto per essere utilizzato!
==============================================
' as DATABASE_PRONTO;