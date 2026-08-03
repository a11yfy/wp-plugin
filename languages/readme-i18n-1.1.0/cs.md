<!-- a11yfy readme 1.1.0 új blokkjai — cs (deepseek-lektorált fordítás, 2026-08-03) -->

### Description — nový odstavec

Tři pracovní režimy: **automatický** (každý nově nahraný soubor se opraví), **ruční** (sami si zvolíte, co opravit) a **na vyžádání** — návštěvníci, kteří kliknou na dosud nepřístupné PDF, si mohou v přístupném dialogovém okně vyžádat přístupnou verzi a po jejím připravení obdrží e-mail, takže platíte jen za dokumenty, které lidé skutečně potřebují.

### External Services — upravená odrážka

* **Když provádíte remediaci PDF** (ručně, hromadnou akcí, prostřednictvím zapnutého automatického režimu nebo když si návštěvník vyžádá přístupnou verzi v režimu na vyžádání, který jste zapnuli): samotný soubor PDF, jeho název a váš API klíč jsou odeslány na `https://a11yfy.com/v1/jobs`. Zpracování probíhá v EU. E-mailová adresa žadatele je uložena pouze na vašem webu a nikdy není odeslána do API a11yfy.

### FAQ — nová otázka + odpověď

= Jak funguje režim „Na vyžádání“? =

Když návštěvník klikne na odkaz na PDF, které neprošlo kontrolou přístupnosti, zobrazí se přístupné dialogové okno. Návštěvník může dokument otevřít tak, jak je, nebo si vyžádat přístupnou verzi zadáním své e-mailové adresy. Plugin dokument remediuje pouze jednou — bez ohledu na to, kolik návštěvníků o něj požádá — a jakmile je přístupná verze k dispozici, pošle e-mail všem, kdo o ni požádali. Od té chvíle všichni návštěvníci na stejném odkazu obdrží přístupný soubor, bez dialogového okna. E-mailová adresa je použita výhradně pro toto jedno oznámení, nikdy není odeslána do API a11yfy a po 30 dnech je automaticky smazána. Texty dialogového okna, oznamovací e-mail a styl tlačítek lze upravit v nastavení a11yfy → Nastavení.

### Changelog — 1.1.0

= 1.1.0 =
* Nový pracovní režim „Na vyžádání“: když návštěvník klikne na PDF, které dosud není přístupné, přístupné dialogové okno mu nabídne otevřít dokument v původní podobě nebo si e-mailem vyžádat přístupnou verzi. Remediace probíhá jen na základě skutečné poptávky.
* Návštěvníci jsou e-mailem informováni, jakmile je přístupná verze připravena; veškeré texty dialogového okna (včetně poznámky k ochraně soukromí) i oznamovací e-mail lze plně upravit v Nastavení.
* Dialogové okno přebírá typografii vaší šablony a u blokových šablon tlačítka automaticky následují styl tlačítek šablony; případně si můžete zvolit vlastní zvýrazňující barvu.
* Pokud zůstatek kreditů nepokryje požadavek návštěvníka, je požadavek pozastaven, správce webu je na to upozorněn e-mailem a remediace se automaticky spustí, jakmile bude k dispozici dostatek kreditů.
* E-mailové adresy žadatelů jsou uchovávány pouze do odeslání oznámení (uchování po dobu 30 dnů), s podporou exportu a výmazu osobních údajů dle WordPress.
