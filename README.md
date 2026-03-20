# CodeDungeon

CodeDungeon is a PHP + MySQL web platform that teaches coding through game-based challenges, AI mentoring, and social progression.

## What’s Included

### Core Coding Modes
- **Bug Hunt Arena**: Fix broken production-style snippets and receive AI obituary feedback.
- **Daily Bug Sprint**: Time-boxed daily challenge flow with score locking and leaderboard integration.
- **Live Coding Arena**: Solve from scratch with hint penalties and concept-graph updates.

### Existing Learning Worlds
- **VocabWorld**: RPG-style progression game with character systems and persistent saves.
- **Grammar Heroes**: Action-oriented learning mode integrated into the same platform shell.

### Platform Features
- OTP-backed onboarding and authentication
- Friends, notifications, profile and favorites
- Creator Studio for lesson/content workflows
- Admin dashboards for analytics and audit visibility

## Tech Stack

<p align="center">
  <img src="https://skillicons.dev/icons?i=html,css,js,php,mysql,docker,vscode" alt="Technology Stack" />
</p>

## Local Setup (Docker)

### 1) Start services
```bash
docker compose up -d --build
```

### 2) Open apps
- Web: http://localhost:8080
- phpMyAdmin: http://localhost:8081

### 3) Database defaults (docker-compose)
- Host: `db` (from containers) / `localhost:3307` (from host)
- Database: `school_portal`
- Root user: `root`
- Root password: `rootpassword`

## First-Run Data Seeding

If challenge content is missing, run seed routes once while logged in:

- Bug Hunt seed: `/play/seed.php`
- Live Coding seed: `/play/live_coding_seed.php`

## AI Integration Notes

- Cerebras integration is used by:
  - `/play/intent_api.php`
  - `/play/obituary.php`
- Diagnostics page:
  - `/cerebras_test.php`
- Shared backend utility:
  - `callCerebras(...)` in `onboarding/config.php`

## Security Practices

- Password hashing for stored credentials
- Prepared PDO statements throughout critical data paths
- Input sanitization and validation on user-facing flows
- Session-based access control on protected endpoints

## Project Structure (high-level)

- `onboarding/` authentication, session bootstrapping, config
- `play/` game modes, AI APIs, and challenge workflows
- `navigation/` social, profile, leaderboards, teacher/admin areas
- `MainGame/` legacy game worlds (VocabWorld, Grammar Heroes)

## Status

The project is actively evolving from the original Word Weavers platform into CodeDungeon, including rebranding, new coding game loops, and AI-assisted feedback flows.

