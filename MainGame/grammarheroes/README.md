## Grammar Heroes

Grammar Heroes is a complete grammar challenge game integrated with Word Weavers.

### Included

- `index.php`: game lobby and player stats
- `game.php`: interactive challenge experience
- `script.js`: question engine, timer, streak system, scoring, and save call
- `save_progress.php`: persistence into `game_scores`, `game_progress`, and `user_gwa`

### Progress Model

- Game type key: `grammar-heroes`
- Saves session score, accuracy, streak, XP, and play time
- Updates cumulative progress and recalculates GWA using shared updater