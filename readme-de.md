# Calendar 0.3.3

Zeigt die nächsten Termine aus einem Kalenderlink. Entwickelt von Liam Perlaki.

Für Datenstrom Yellow gibt es keine offizielle Kalender-Erweiterung, das hier ist eine kleine. Sie
liest einen geteilten Kalender über HTTP, zeigt die nächsten Termine auf einer Seite und lässt
Besucher*innen einen einzelnen Termin speichern. Ohne API-Schlüssel, ohne Datenbank, ohne
JavaScript.

## Wie man eine Erweiterung installiert

[ZIP-Datei herunterladen](https://github.com/pfadfinder26/yellow-calendar/archive/refs/heads/main.zip) und in den Ordner `system/extensions` kopieren. [Mehr über Erweiterungen](https://github.com/annaesvensson/yellow-update).

## Wie man Termine zeigt

Den Link eines geteilten Kalenders in eine Seite schreiben:

    [calendar https://cloud.example.org/apps/calendar/p/TOKEN]

Die Argumente sind der Link, wie viele Termine gezeigt werden, dann beliebig viele Optionen:

    [calendar https://cloud.example.org/apps/calendar/p/TOKEN 5 name:GuSp unique]

`name:GuSp` behält die Kalender, deren Name „GuSp“ enthält, `name:GuSp,Events` behält beide.
`unique` zeigt einen wiederkehrenden Termin nur einmal, mit dem nächsten Mal, damit ein
wöchentlicher Heimabend nicht die ganze Liste füllt. `each:2` nimmt aus jedem Kalender höchstens
zwei Termine, eine Stufenseite zeigt so die nächsten zwei Heimabende neben den nächsten zwei
Terminen der Gruppe. `month` zeigt einen Monat als Wochenraster statt als Liste, `months:3` zeigt drei Monate, ab
diesem. Ein Termin zeigt Ort und Notizen unter dem Titel, wenn sie im Kalender stehen. Ein Termin, dessen Website die Seite ist, auf der er steht, wird als Text gezeigt, nicht als Link
auf sich selbst. Ein einzeln geänderter Termin einer Wiederholung, ein Heimabend, der diese Woche woanders ist, ersetzt diese
Wiederholung, statt neben ihr zu stehen.

Unter einer Liste stehen die Kalender zum Abonnieren und der Link, der den Ordner in der Cloud
öffnet. Ein Browser kann nicht selbst abonnieren, deshalb kopiert ein Klick auf einen Kalender
seinen Link in die Zwischenablage, `CalendarLabelCopied` sagt, was dabei kurz erscheint. Ohne
JavaScript bleibt der Link ein Link.
Über der Monatsansicht stehen Links auf die Monate davor und danach, sie kommen ohne JavaScript aus
und fragen den Monat im Ort ab, `/termine/month:2026-11/`, dazu ein Link zurück zu diesem Monat.

## Einen einzelnen Termin anbieten

Eine Seite, auf die ein Termin im Kalender verlinkt, kann diesen Termin zum Speichern anbieten:

    [calendarevent]

Die Erweiterung sucht den Termin, dessen Website diese Seite ist, und zeigt einen Knopf, der ihn
als `.ics`-Datei speichert, und gar nichts, wenn kein Kalender die Seite nennt. `CalendarUrl` in
den Systemeinstellungen sagt, welche Kalender durchsucht werden, ein eigener Link sagt es pro
Seite. Dafür wird nichts geholt, die Kalender werden so gelesen, wie sie auf dem Server liegen, und
ein Kalender, der die Seite nicht nennt, wird gar nicht erst zerlegt.

**Nextcloud:** den Kalender in der Kalender-App teilen, „Link kopieren“, und diesen Link genau so
verwenden. Die Kurzform sagt dasselbe und hält eine Seite lesbar:

    [calendar nextcloud://cloud.example.org/TOKEN]

Ein Link kann mehrere Kalender enthalten, das Token hat dann einen Teil pro Kalender, mit Bindestrich
getrennt, und die Erweiterung liest alle. `name:GuSp` behält die Termine der Kalender, deren Name „GuSp“ enthält,
so zeigt eine Stufenseite ihre eigenen Termine aus demselben Link.

Jede andere Adresse, die eine iCalendar-Datei liefert, geht auch, zum Beispiel eine `.ics`-Datei auf
dem eigenen Webspace.

## Was gezeigt wird

Jeder Termin zeigt, wann er ist, wie er heißt, wo er stattfindet und, wenn ein Link mehrere
Kalender enthält, aus welchem Kalender er kommt, als Schildchen in der Farbe, die der Kalender in
der Cloud hat. Unter der Liste lässt sich jeder Kalender einzeln abonnieren. Ein Termin, der im Kalender eine Website hat, das `URL`-Feld eines Termins, in Apple Kalender
„Website“ und in Nextcloud als Link zu sehen, wird zum Link auf diese Seite, in der Liste wie in der
Monatsansicht. Dazu hat jeder Termin einen Link, der ihn als `.ics`-Datei speichert, ausgeliefert von dieser Erweiterung unter
`/calendar-event/…`. Unter der Liste steht ein Link zurück zum Kalender. Wiederkehrende Termine
werden aufgefaltet, `FREQ` täglich, wöchentlich, monatlich und jährlich mit `INTERVAL`, `COUNT`,
`UNTIL` und `EXDATE`, und sie behalten ihre Ortszeit, wenn die Sommerzeit wechselt.

## Einstellungen

`CalendarEntries` wie viele Termine ein `[calendar]` ohne Zahl zeigt, `5`  
`CalendarMonthsAhead` wie weit wiederkehrende Termine aufgefaltet werden, `18`  
`CalendarMonths` wie viele Monate `month` zeigt, `1`  
`CalendarCacheTime` wie lange ein geholter Kalender behalten wird, in Sekunden, `3600`  
`CalendarUrl` ein Link, der gilt, wenn `[calendar]` keinen hat  
`CalendarLocation` wo einzelne Termine ausgeliefert werden, `/calendar-event/`  
`CalendarLabelOpen`, `CalendarLabelSubscribe`, `CalendarLabelDownload`, `CalendarLabelToday`,
`CalendarLabelEmpty` die Wörter auf der Seite

Die geholten Kalender liegen in `system/extensions/calendar-*.cache`, damit ein Seitenaufruf nicht
auf den anderen Server wartet. Ist ein Kalender nicht erreichbar, gilt die letzte Kopie.

**Datenschutz:** den Kalender holt der eigene Webserver, nicht die Besucher*innen, niemand sonst
erfährt also deren IP-Adresse.

**Vertrauen:** der Link in einer Seite sagt dem Webserver, was er holen soll, Seiten sollten also
nur Leute bearbeiten, denen man den Server anvertraut, was bei einer Yellow-Website ohnehin so ist.

Hast du Fragen? [Hier gibt's Hilfe](https://datenstrom.se/yellow/help/).
