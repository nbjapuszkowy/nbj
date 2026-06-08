NBJapuszkowy Bank — wersja na hosting PHP + JSON

Jak wrzucić:
1. Wgraj całą zawartość folderu NBJapuszkowy_Bank_HOSTING na hosting.
2. Pliki index.html i api.php muszą być w tym samym folderze.
3. Folder data zostaw na hostingu. W nim jest plik nbj-data.json.
4. Hosting musi obsługiwać PHP.
5. Panel admina: hasło NBJ2026!

Gdzie są dane:
- dane banku są w pliku: data/nbj-data.json
- folder data ma .htaccess, żeby przeglądarka nie pokazywała pliku JSON publicznie

Ważne:
- Nie odpalaj samego index.html z komputera, bo wtedy PHP nie zadziała.
- Otwieraj stronę przez adres domeny / hostingu.
