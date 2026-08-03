<!-- a11yfy readme 1.1.0 új blokkjai — pl (deepseek-lektorált fordítás, 2026-08-03) -->

Az alábbiakban a `readme-additions-en.md` teljes tartalmának lengyel fordítása következik, a wp.org readme konvencióinak megfelelően.

---

# readme.txt új/módosult blokkjai (1.1.0) — PL fordítás

## Description — új bekezdés
Trzy tryby działania: **automatyczny** (każdy nowo przesłany plik jest natychmiast dostosowywany), **ręczny** (sam wybierasz, co poprawić) oraz **na żądanie** — odwiedzający, którzy klikną niedostępny jeszcze plik PDF, mogą poprosić o wersję dostosowaną do potrzeb osób niepełnosprawnych w dostępnym oknie dialogowym i otrzymać e-mail, gdy będzie gotowa. Dzięki temu płacisz tylko za dokumenty, których użytkownicy naprawdę potrzebują.

## External Services — módosult bullet
* **Gdy zlecasz dostosowanie pliku PDF** (ręcznie, poprzez akcję zbiorczą, włączony tryb automatyczny lub gdy odwiedzający poprosi o wersję dostępną w trybie na żądanie): sam plik PDF, jego nazwa oraz Twój klucz API są przesyłane na adres `https://a11yfy.com/v1/jobs`. Przetwarzanie odbywa się na terenie UE. Adres e-mail osoby zgłaszającej jest przechowywany wyłącznie na Twojej stronie i nigdy nie jest wysyłany do API a11yfy.

## FAQ — új kérdés + válasz
= Jak działa tryb „Na żądanie”? =

Gdy odwiedzający kliknie łącze do pliku PDF, który nie przeszedł wstępnej kontroli dostępności, pojawia się dostępne okno dialogowe. Odwiedzający może otworzyć dokument w obecnej postaci lub poprosić o wersję dostępną, podając swój adres e-mail. Wtyczka dostosowuje dokument tylko raz — niezależnie od tego, ile osób o niego poprosi — i wysyła wiadomość e-mail do wszystkich zgłaszających, gdy tylko wersja dostępna będzie gotowa. Od tego momentu wszyscy odwiedzający otrzymują dostępny plik pod tym samym łączem, bez okna dialogowego. Adres e-mail jest wykorzystywany wyłącznie do tego jednego powiadomienia, nigdy nie jest przesyłany do API a11yfy i jest automatycznie usuwany po 30 dniach. Teksty w oknie dialogowym, wiadomość e-mail z powiadomieniem oraz styl przycisków można dostosować w sekcji a11yfy → Ustawienia.

## Changelog — 1.1.0
= 1.1.0 =
* Nowy tryb działania „Na żądanie”: gdy odwiedzający kliknie plik PDF, który nie jest jeszcze dostępny, w oknie dialogowym może otworzyć go w obecnej postaci lub poprosić o wersję dostępną, podając adres e-mail. Dostosowywanie odbywa się tylko na rzeczywiste zapotrzebowanie.
* Odwiedzający otrzymują powiadomienie e-mail, gdy tylko wersja dostępna będzie gotowa; każdy tekst w oknie dialogowym (wraz z informacją o ochronie prywatności) oraz treść powiadomienia e-mail są w pełni konfigurowalne w Ustawieniach.
* Okno dialogowe dziedziczy typografię motywu, a w motywach blokowych przyciski automatycznie dopasowują się do stylu przycisków motywu; alternatywnie można wybrać własny kolor akcentu.
* Jeśli saldo kredytów nie wystarcza na realizację zgłoszenia odwiedzającego, zgłoszenie jest wstrzymywane, właściciel witryny otrzymuje ostrzeżenie e-mailem, a dostosowywanie rozpoczyna się automatycznie, gdy tylko dostępna będzie odpowiednia liczba kredytów.
* Adresy e-mail zgłaszających są przechowywane wyłącznie do momentu wysłania powiadomienia (retencja 30 dni), z obsługą eksportu i usuwania danych osobowych zgodnie z mechanizmem prywatności WordPress.
