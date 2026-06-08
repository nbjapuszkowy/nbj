NBJapuszkowy Bank — wersja bez folderu data

Wgraj wszystkie pliki z tej paczki do jednego katalogu na hostingu:
- index.html
- api.php
- nbj-data.json
- .htaccess

Panel admina:
NBJ2026!

Wymagania:
- hosting z PHP
- plik nbj-data.json musi mieć możliwość zapisu przez PHP

Gdyby po rejestracji wyskoczył błąd zapisu:
- ustaw uprawnienia pliku nbj-data.json na 664 lub 666
- ewentualnie katalogu, w którym są pliki, na 755 albo 775

Ważne:
- .htaccess blokuje bezpośrednie wejście w nbj-data.json na hostingu Apache.
- Jeśli hosting działa na nginx i ignoruje .htaccess, zablokuj dostęp do nbj-data.json w panelu hostingu.
