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

Il faudrait un système complet pour :

- Traduire les genres (par exemple Adventure > Aventure, Racing > Course)
