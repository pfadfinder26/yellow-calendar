# Calendar 0.1.1

Show upcoming dates from a calendar link. Developed by Liam Perlaki.

There is no official calendar extension for Datenstrom Yellow, this is a small one. It reads a
shared calendar over HTTP, shows the next dates on a page and lets visitors save a single date.
No API key, no database, no JavaScript.

## How to install an extension

[Download ZIP file](https://github.com/pfadfinder26/yellow-calendar/archive/refs/heads/main.zip) and copy it into your `system/extensions` folder. [Learn more about extensions](https://github.com/annaesvensson/yellow-update).

## How to show dates

Paste the link of a shared calendar into a page:

    [calendar https://cloud.example.org/apps/calendar/p/TOKEN]

The arguments are the link, how many dates to show, then any number of options:

    [calendar https://cloud.example.org/apps/calendar/p/TOKEN 5 name:GuSp unique]

`name:GuSp` keeps the calendars whose name contains "GuSp". `unique` shows a repeating date only
once, with its next occurrence, so a weekly Heimabend does not fill the whole list. `month` shows a
month as a grid of weeks instead of a list, `months:3` shows three months, starting with this one.
Above a month view there are links to the months before and after, they work without JavaScript by
asking for a month in the location, `/termine/month:2026-11/`, and a link back to this month.

**Nextcloud:** share a calendar in the calendar app, "Copy link", and use that link as it is. The
short form says the same thing and keeps a page readable:

    [calendar nextcloud://cloud.example.org/TOKEN]

A link can hold several calendars, the token then has one part per calendar, separated by a dash,
and the extension reads all of them. `name:GuSp` keeps the dates of the calendars whose name contains "GuSp", so a section page can
show its own dates from the same link.

Any other address that returns an iCalendar file works too, for example an `.ics` file on your own
web server.

## What is shown

Each date shows when it is, what it is called, where it happens and, when a link holds several
calendars, which calendar it comes from, as a label in the color that calendar has in the cloud.
Below the list every calendar can be subscribed to on its own. A date that has a website in the calendar, the `URL` of an event, which Apple Calendar calls
"website" and Nextcloud shows as a link, becomes a link to that page, in the list as well as in the
month view. Each date also has a link that saves it as an `.ics` file, served by this extension at `/calendar-event/…`. Below the list there is a
link back to the calendar itself. Repeating dates are unfolded, `FREQ` daily, weekly, monthly and
yearly with `INTERVAL`, `COUNT`, `UNTIL` and `EXDATE`, and they keep their local time when daylight
saving time changes.

## Settings

`CalendarEntries` how many dates a `[calendar]` without a number shows, `5`  
`CalendarMonthsAhead` how far ahead repeating dates are unfolded, `18`  
`CalendarMonths` how many months `month` shows, `1`  
`CalendarCacheTime` how long a fetched calendar is kept, in seconds, `3600`  
`CalendarUrl` a link used when `[calendar]` has none  
`CalendarLocation` where single dates are served, `/calendar-event/`  
`CalendarLabelOpen`, `CalendarLabelSubscribe`, `CalendarLabelDownload`, `CalendarLabelToday`,
`CalendarLabelEmpty` the words on the page

The fetched calendars are kept in `system/extensions/calendar-*.cache`, so a visit does not wait for
the other server. A calendar that cannot be reached falls back to the last copy.

**Data protection:** the calendar is fetched by your web server, not by your visitors, so nobody
else learns their IP address.

**Trust:** the link in a page tells the web server what to fetch, so only people you trust with the
server should be able to edit pages, which is how a Yellow website works anyway.

Do you have questions? [Get help](https://datenstrom.se/yellow/help/).
