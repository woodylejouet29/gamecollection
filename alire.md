php scripts/igdb_sync.php --from-year=2020 --to-year=2024
php scripts/igdb_sync.php --year=2028 --no-images
php scripts/igdb_sync.php --game-platforms

php scripts/igdb_sync.php --games --from-year=2024 --to-year=2030 --no-images

php scripts/igdb_sync.php --games --from-year=1950 --to-year=1960

php scripts/igdb_sync.php --reset

php scripts/igdb_sync.php --platforms

php scripts/igdb_sync.php --platforms --games --versions --from-year=2001 --to-year=2009

php scripts/igdb_sync.php --platforms --games --versions --year=2028

php scripts/igdb_sync.php --versions


Dans la page de recherche, il y a plusieurs problèmes :

- Le chargement de la page est beaucoup trop long (~8 secondes), idem pour charger de nouveaux résultats après filtres/recherche (~8 secondes).
- Certains filtres ne fonctionnent pas : il faut tous les tester.
- Aucun jeu ne s’affiche à l’arrivée sur la page (est-ce normal ?).
- Le toggle des 3 vues ne fonctionne pas (on ne peut pas cliquer pour changer de vue).
- Sur mobile, le bouton "filtres" en bas à gauche n’apparaît que lorsqu’on défile vers le bas.
- Quand on clique pour voir les filtres, la fenêtre de filtres est vraiment moche (voir l’image ci-jointe).
- L’icône de rechargement entre chaque recherche (rond qui tourne) fait des animations bizarres et on ne la voit pas assez.

Dans la page d'accueil

- Il faut remplacer le bloc "Prochaines sorties" par "Ca sort aujourd'hui" avec tous les jeux qui sortent de la date actuelle
- Sur les images de jeux il faut retirer le badge de la date

Marque : logo + tagline
Réseaux : icones (twitter, facebook, instagram)
Contact : mail et discord (bouton contacter)
Support : non, les pages seront à faire plus tard
Légal : non, les pages seront à faire plus tard

Copyright : oui
Crédibilité : oui
Mobile : a toi de voir ce qui est le plus pratique

php scripts/test_uniqueness.php
php scripts/test_circuit_breaker.php
php scripts/test_rating_restriction.php
php scripts/weekly_cleanup.php

Database Size : 1137 MB

[
  {
    "table_name": "games",
    "total": "1059 MB",
    "bytes": 1110204416
  },
  {
    "table_name": "game_platforms",
    "total": "67 MB",
    "bytes": 70434816
  },
  {
    "table_name": "logs",
    "total": "128 kB",
    "bytes": 131072
  },
  {
    "table_name": "platforms",
    "total": "120 kB",
    "bytes": 122880
  },
  {
    "table_name": "game_versions",
    "total": "104 kB",
    "bytes": 106496
  },
  {
    "table_name": "reviews",
    "total": "64 kB",
    "bytes": 65536
  },
  {
    "table_name": "users",
    "total": "48 kB",
    "bytes": 49152
  },
  {
    "table_name": "collection_entries",
    "total": "48 kB",
    "bytes": 49152
  },
  {
    "table_name": "user_platforms",
    "total": "40 kB",
    "bytes": 40960
  },
  {
    "table_name": "user_genres",
    "total": "40 kB",
    "bytes": 40960
  },
  {
    "table_name": "wishlist",
    "total": "24 kB",
    "bytes": 24576
  }
]


[
  {
    "table_name": "games",
    "table_data": "371 MB",
    "indexes": "179 MB",
    "toast": "509 MB"
  },
  {
    "table_name": "game_platforms",
    "table_data": "29 MB",
    "indexes": "38 MB",
    "toast": "0 bytes"
  },
  {
    "table_name": "logs",
    "table_data": "48 kB",
    "indexes": "48 kB",
    "toast": "8192 bytes"
  },
  {
    "table_name": "platforms",
    "table_data": "40 kB",
    "indexes": "48 kB",
    "toast": "8192 bytes"
  },
  {
    "table_name": "game_versions",
    "table_data": "24 kB",
    "indexes": "32 kB",
    "toast": "24 kB"
  },
  {
    "table_name": "reviews",
    "table_data": "8192 bytes",
    "indexes": "48 kB",
    "toast": "8192 bytes"
  },
  {
    "table_name": "collection_entries",
    "table_data": "8192 bytes",
    "indexes": "32 kB",
    "toast": "8192 bytes"
  },
  {
    "table_name": "users",
    "table_data": "8192 bytes",
    "indexes": "32 kB",
    "toast": "8192 bytes"
  },
  {
    "table_name": "user_platforms",
    "table_data": "8192 bytes",
    "indexes": "32 kB",
    "toast": "0 bytes"
  },
  {
    "table_name": "user_genres",
    "table_data": "8192 bytes",
    "indexes": "32 kB",
    "toast": "0 bytes"
  },
  {
    "table_name": "wishlist",
    "table_data": "0 bytes",
    "indexes": "24 kB",
    "toast": "0 bytes"
  }
]

[
  {
    "index_name": "idx_games_title_trgm",
    "index_size": "34 MB"
  },
  {
    "index_name": "idx_games_rating_sort",
    "index_size": "27 MB"
  },
  {
    "index_name": "idx_games_release_sort",
    "index_size": "22 MB"
  },
  {
    "index_name": "idx_games_title_sort",
    "index_size": "18 MB"
  },
  {
    "index_name": "games_slug_key",
    "index_size": "16 MB"
  },
  {
    "index_name": "idx_games_slug",
    "index_size": "15 MB"
  },
  {
    "index_name": "games_pkey",
    "index_size": "11 MB"
  },
  {
    "index_name": "games_igdb_id_key",
    "index_size": "10 MB"
  },
  {
    "index_name": "idx_games_igdb_id",
    "index_size": "10152 kB"
  },
  {
    "index_name": "idx_games_cached_at",
    "index_size": "7080 kB"
  },
  {
    "index_name": "idx_games_genres_gin",
    "index_size": "4264 kB"
  },
  {
    "index_name": "idx_games_version_parent_igdb_id",
    "index_size": "3120 kB"
  },
  {
    "index_name": "idx_games_platform_ids",
    "index_size": "2456 kB"
  }
]

[
  {
    "relname": "games",
    "row_estimate": 268377,
    "total": "1059 MB"
  },
  {
    "relname": "game_platforms",
    "row_estimate": 448472,
    "total": "67 MB"
  },
  {
    "relname": "logs",
    "row_estimate": 250,
    "total": "128 kB"
  },
  {
    "relname": "platforms",
    "row_estimate": 220,
    "total": "120 kB"
  },
  {
    "relname": "game_versions",
    "row_estimate": 137,
    "total": "104 kB"
  },
  {
    "relname": "reviews",
    "row_estimate": 1,
    "total": "64 kB"
  },
  {
    "relname": "users",
    "row_estimate": 2,
    "total": "48 kB"
  },
  {
    "relname": "collection_entries",
    "row_estimate": 7,
    "total": "48 kB"
  },
  {
    "relname": "user_genres",
    "row_estimate": 5,
    "total": "40 kB"
  },
  {
    "relname": "user_platforms",
    "row_estimate": 3,
    "total": "40 kB"
  },
  {
    "relname": "wishlist",
    "row_estimate": 0,
    "total": "24 kB"
  }
]