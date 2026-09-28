<?php
// Calendar extension, https://github.com/pfadfinder26/yellow-calendar
// Based on Datenstrom Yellow, https://datenstrom.se/yellow/

class YellowCalendar {
    const VERSION = "0.3.0";
    public $yellow;         // access to API
    
    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("calendarUrl", "");
        $this->yellow->system->setDefault("calendarLocation", "/calendar-event/");
        $this->yellow->system->setDefault("calendarLink", "");
        $this->yellow->system->setDefault("calendarEntries", "5");
        $this->yellow->system->setDefault("calendarMonthsAhead", "18");
        $this->yellow->system->setDefault("calendarMonths", "1");
        $this->yellow->system->setDefault("calendarCacheTime", "3600");
        $this->yellow->system->setDefault("calendarLabelOpen", "Open calendar");
        $this->yellow->system->setDefault("calendarLabelSubscribe", "Subscribe");
        $this->yellow->system->setDefault("calendarLabelToday", "This month");
        $this->yellow->system->setDefault("calendarLabelDownload", "Save date");
        $this->yellow->system->setDefault("calendarLabelEmpty", "No dates at the moment.");
    }
    
    // Handle request, serve a single event as a calendar file
    public function onRequest($scheme, $address, $base, $location, $fileName) {
        $prefix = $this->yellow->system->get("calendarLocation");
        if (substru($location, 0, strlenu($prefix))!=$prefix) return 0;
        if (!preg_match("#^".preg_quote($prefix, "#")."([0-9a-f]{8})/(\d+)/#", $location, $matches)) return 0;
        list($dummy, $hash, $start) = $matches;
        $event = $this->getEventFromCache($hash, intval($start));
        if (is_null($event)) return $this->yellow->sendStatus(404);
        $fileData = $this->getEventData($event);
        $name = $this->yellow->lookup->normaliseName($event["summary"], true, true, true);
        if (is_string_empty($name)) $name = "event";
        return $this->yellow->sendData(200, array("Content-Type" => "text/calendar; charset=utf-8",
            "Content-Disposition" => "attachment; filename=\"$name.ics\"",
            "X-Content-Type-Options" => "nosniff",
            "Cache-Control" => "max-age=3600"), $fileData);
    }
    
    // Handle page content element
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        $output = null;
        if ($name=="calendar" && ($type=="block" || $type=="inline")) {
            $arguments = $this->yellow->toolbox->getTextArguments($text);
            list($url, $entries) = $arguments;
            $options = array_values(array_filter(array_slice($arguments, 2)));
            $filter = "";
            foreach ($options as $option) {
                if (substru($option, 0, 5)=="name:") $filter = $option;
            }
            $unique = in_array("unique", $options);
            $each = 0;
            foreach ($options as $option) {
                if (preg_match("/^each:(\d+)$/", $option, $matches)) $each = intval($matches[1]);
            }
            $months = in_array("month", $options) ? intval($this->yellow->system->get("calendarMonths")) : 0;
            foreach ($options as $option) {
                if (preg_match("/^months:(\d+)$/", $option, $matches)) $months = intval($matches[1]);
            }
            if (is_string_empty($url)) $url = $this->yellow->system->get("calendarUrl");
            if (is_string_empty($url)) return $this->getErrorHtml("Please add a calendar link!");
            if (!is_numeric($entries)) $entries = $this->yellow->system->get("calendarEntries");
            $events = $sources = array();
            foreach ($this->getSourceUrls($url) as $sourceUrl) {
                $fileData = $this->getCalendarData($sourceUrl);
                if (is_null($fileData)) return $this->getErrorHtml("Can't read calendar '$sourceUrl'!");
                $timeFrom = $months>0 ? $this->getMonthRequested($page) : strtotime("today");
                $eventsSource = $this->getEvents($fileData, 0, $this->getHash($sourceUrl), $timeFrom);
                $events = array_merge($events, $eventsSource);
                $sources[] = array("url" => $sourceUrl, "name" => $this->getCalendarName($fileData),
                    "color" => $this->getCalendarColor($fileData));
            }
            $sources = $this->getSourcesSelected($sources, $filter);
            $showName = count($sources)>1;
            $page->setLastModified(time());
            if ($months>0) {
                $events = $this->getEventsSelected($events, $filter, 0, false);
                $output = $this->getMonthsHtml($events, $months, $showName, $page);
            } else {
                $events = $this->getEventsSelected($events, $filter, intval($entries), $unique, $each);
                $output = $this->getCalendarHtml($events, "", $showName, array());
            }
            $output .= $this->getFooterHtml($this->getLinkUrl($url), $sources);
        }
        if ($name=="calendarevent" && ($type=="block" || $type=="inline")) {
            list($url) = $this->yellow->toolbox->getTextArguments($text);
            if (is_string_empty($url)) $url = $this->yellow->system->get("calendarUrl");
            if (is_string_empty($url)) return $this->getErrorHtml("Please add a calendar link!");
            $output = $this->getPageEventHtml($page, $url);
        }
        return $output;
    }

    // Return the date of this page as a button, when an event of a calendar links here
    // nothing is fetched for this, the calendars are read the way they lie on this server
    public function getPageEventHtml($page, $url) {
        $event = $this->getPageEvent($page, $url);
        if (is_null($event)) return "";
        $output = "<p class=\"calendar-add\">";
        $output .= "<a class=\"button\" href=\"".htmlspecialchars($this->getEventLocation($event))."\">";
        $output .= htmlspecialchars($this->yellow->system->get("calendarLabelDownload"))."</a>";
        $output .= "</p>\n";
        return $output;
    }

    // Return the next event that links to this page, null if no calendar mentions it
    public function getPageEvent($page, $url) {
        $location = $this->yellow->system->get("coreServerBase").$page->location;
        foreach ($this->getSourceUrls($url) as $sourceUrl) {
            $fileData = $this->getCalendarData($sourceUrl, true);
            if (is_null($fileData) || strposu($fileData, $location)===false) continue;
            foreach ($this->getEvents($fileData, 0, $this->getHash($sourceUrl), strtotime("today")) as $event) {
                if ($this->isLinkingTo($event["url"], $location)) return $event;
            }
        }
        return null;
    }

    // Check if a link of an event leads to a location of this website
    public function isLinkingTo($url, $location) {
        if (is_string_empty($url)) return false;
        $path = rtrim(parse_url($url, PHP_URL_PATH), "/");
        return !is_string_empty($path) && $path==rtrim($location, "/");
    }
    
    // Return calendar HTML
    public function getCalendarHtml($events, $link, $showName = false, $sources = array()) {
        $output = "<div class=\"calendar\">\n";
        if (is_array_empty($events)) {
            $output .= "<p class=\"calendar-empty\">".htmlspecialchars($this->yellow->system->get("calendarLabelEmpty"))."</p>\n";
        } else {
            $output .= "<ul>\n";
            foreach ($events as $event) {
                $output .= "<li>\n";
                $output .= "<time datetime=\"".htmlspecialchars($this->getDateFormatted($event, $event["start"], "c"))."\">";
                $output .= htmlspecialchars($this->getDateText($event))."</time>\n";
                $output .= "<span class=\"calendar-summary\">";
                if (!is_string_empty($event["url"])) {
                    $output .= "<a href=\"".htmlspecialchars($event["url"])."\">".htmlspecialchars($event["summary"])."</a>";
                } else {
                    $output .= htmlspecialchars($event["summary"]);
                }
                $output .= "</span>\n";
                if ($showName && !is_string_empty($event["calendar"])) {
                    $class = $this->yellow->lookup->normaliseClass($event["calendar"]);
                    $output .= "<span class=\"calendar-name ".htmlspecialchars($class)."\"";
                    if (!is_string_empty($event["color"])) {
                        $output .= " style=\"".htmlspecialchars($this->getColorStyle($event["color"]))."\"";
                    }
                    $output .= ">".htmlspecialchars($event["calendar"])."</span>\n";
                }
                if (!is_string_empty($event["location"])) {
                    $output .= "<span class=\"calendar-location\">".htmlspecialchars($event["location"])."</span>\n";
                }
                if (!is_string_empty($event["description"])) {
                    $output .= "<span class=\"calendar-description\">".
                        nl2br(htmlspecialchars($event["description"]))."</span>\n";
                }
                $output .= "<a class=\"calendar-download\" href=\"".htmlspecialchars($this->getEventLocation($event))."\">";
                $output .= htmlspecialchars($this->yellow->system->get("calendarLabelDownload"))."</a>\n";
                $output .= "</li>\n";
            }
            $output .= "</ul>\n";
        }
        return $output;
    }
    
    // Return the links below a calendar, subscribing and opening it
    public function getFooterHtml($link, $sources) {
        $output = "";
        if (!is_array_empty($sources)) {
            $output .= "<p class=\"calendar-subscribe\">";
            $output .= htmlspecialchars($this->yellow->system->get("calendarLabelSubscribe")).": ";
            $links = array();
            foreach ($sources as $source) {
                $name = is_string_empty($source["name"]) ? $this->yellow->system->get("calendarLabelSubscribe") : $source["name"];
                $style = is_string_empty($source["color"]) ? "" :
                    " style=\"".htmlspecialchars($this->getColorStyle($source["color"]))."\"";
                $links[] = "<a class=\"calendar-name ".htmlspecialchars($this->yellow->lookup->normaliseClass($name)).
                    "\"$style href=\"".htmlspecialchars($source["url"])."\">".htmlspecialchars($name)."</a>";
            }
            $output .= implode(", ", $links)."</p>\n";
        }
        $output .= "<p class=\"calendar-link\"><a href=\"".htmlspecialchars($link)."\">";
        $output .= htmlspecialchars($this->yellow->system->get("calendarLabelOpen"))."</a></p>\n";
        $output .= "</div>\n";
        return $output;
    }
    
    // Return the month a visitor asked for, this month if there is none
    public function getMonthRequested($page) {
        $month = $page->getRequest("month");
        if (preg_match("/^(\d{4})-(\d{2})$/", $month, $matches)) {
            $time = mktime(0, 0, 0, intval($matches[2]), 1, intval($matches[1]));
            $timeMin = strtotime("-5 years");
            $timeMax = strtotime("+5 years");
            if ($time && $time>=$timeMin && $time<=$timeMax) return $time;
        }
        return strtotime("first day of this month 00:00");
    }
    
    // Return the months of a calendar, as a grid of weeks, with links to the months next to it
    public function getMonthsHtml($events, $months, $showName, $page) {
        $output = "<div class=\"calendar calendar-month\">\n";
        $timeFirst = $this->getMonthRequested($page);
        $output .= $this->getPaginationHtml($page, $timeFirst, $months);
        $time = $timeFirst;
        for ($number = 0; $number<$months; ++$number) {
            $output .= $this->getMonthHtml($events, $time, $showName);
            $time = strtotime("+1 month", $time);
        }
        return $output;
    }
    
    // Return the links to the months before and after the ones shown
    public function getPaginationHtml($page, $timeFirst, $months) {
        $location = $page->getLocation(true);
        $timePrevious = strtotime("-$months months", $timeFirst);
        $timeNext = strtotime("+$months months", $timeFirst);
        $output = "<p class=\"calendar-pagination\">";
        $output .= "<a class=\"previous\" href=\"".htmlspecialchars($this->getMonthLocation($location, $timePrevious))."\">";
        $output .= $this->getMonthTextHtml($timePrevious)."</a>";
        if (date("Ym", $timeFirst)!=date("Ym")) {
            $output .= "<a class=\"today\" href=\"".htmlspecialchars($location)."\">";
            $output .= htmlspecialchars($this->yellow->system->get("calendarLabelToday"))."</a>";
        }
        $output .= "<a class=\"next\" href=\"".htmlspecialchars($this->getMonthLocation($location, $timeNext))."\">";
        $output .= $this->getMonthTextHtml($timeNext)."</a>";
        $output .= "</p>\n";
        return $output;
    }
    
    // Return the name of a month, long and short, the theme picks one
    public function getMonthTextHtml($time) {
        $language = $this->yellow->language;
        return "<span class=\"long\">".htmlspecialchars($language->getDateFormatted($time, "F Y"))."</span>".
            "<span class=\"short\">".htmlspecialchars($language->getDateFormatted($time, "M"))."</span>";
    }
    
    // Return the location of a page showing a different month
    public function getMonthLocation($location, $time) {
        return $location.$this->yellow->lookup->normaliseArguments("month:".date("Y-m", $time), false);
    }
    
    // Return one month, as a grid of weeks
    public function getMonthHtml($events, $time, $showName) {
        $language = $this->yellow->language;
        $dayFirst = intval(date("N", $time));
        $daysMonth = intval(date("t", $time));
        $weekdays = preg_split("/\s*,\s*/", $language->getText("coreDateWeekdays"));
        $output = "<table>\n<caption>".htmlspecialchars($language->getDateFormatted($time, "F Y"))."</caption>\n";
        $output .= "<thead>\n<tr>";
        foreach ($weekdays as $weekday) {
            $output .= "<th scope=\"col\"><abbr title=\"".htmlspecialchars($weekday)."\">";
            $output .= htmlspecialchars(substru($weekday, 0, 2))."</abbr></th>";
        }
        $output .= "</tr>\n</thead>\n<tbody>\n<tr>";
        for ($column = 1; $column<$dayFirst; ++$column) $output .= "<td class=\"calendar-empty-day\"></td>";
        for ($day = 1; $day<=$daysMonth; ++$day) {
            $timeDay = mktime(0, 0, 0, intval(date("n", $time)), $day, intval(date("Y", $time)));
            $class = date("Ymd", $timeDay)==date("Ymd") ? " class=\"calendar-today\"" : "";
            $output .= "<td$class><span class=\"calendar-day\">$day</span>";
            foreach ($this->getEventsOfDay($events, $timeDay) as $event) {
                $tag = is_string_empty($event["url"]) ? "span" : "a";
                $output .= "<$tag class=\"calendar-event\"";
                if (!is_string_empty($event["url"])) $output .= " href=\"".htmlspecialchars($event["url"])."\"";
                if (!is_string_empty($event["color"])) {
                    $output .= " style=\"".htmlspecialchars($this->getColorStyle($event["color"]))."\"";
                }
                $output .= " title=\"".htmlspecialchars($this->getDateText($event)." ".$event["summary"])."\">";
                if (!$event["allDay"]) {
                    $output .= "<span class=\"calendar-time\">".
                        htmlspecialchars($this->getDateFormatted($event, $event["start"], "H:i"))."</span> ";
                }
                $output .= htmlspecialchars($event["summary"])."</$tag>";
            }
            $output .= "</td>";
            if (intval(date("N", $timeDay))==7 && $day<$daysMonth) $output .= "</tr>\n<tr>";
        }
        $dayLast = intval(date("N", mktime(0, 0, 0, intval(date("n", $time)), $daysMonth, intval(date("Y", $time)))));
        for ($column = $dayLast; $column<7; ++$column) $output .= "<td class=\"calendar-empty-day\"></td>";
        $output .= "</tr>\n</tbody>\n</table>\n";
        return $output;
    }
    
    // Return the events of one day
    public function getEventsOfDay($events, $timeDay) {
        $found = array();
        $timeNext = strtotime("+1 day", $timeDay);
        foreach ($events as $event) {
            $end = $event["end"] ? $event["end"] : $event["start"];
            if ($event["start"]<$timeNext && $end>$timeDay) $found[] = $event;
        }
        return $found;
    }
    
    // Return date of an event, as text, in the time zone of the calendar entry
    public function getDateText($event) {
        $formatDate = $this->yellow->language->getText("coreDateFormatMedium");
        $end = $event["allDay"] ? $event["end"]-1 : $event["end"];
        $text = $this->getDateFormatted($event, $event["start"], $formatDate);
        if (!$event["allDay"]) $text .= ", ".$this->getDateFormatted($event, $event["start"], "H:i");
        $sameDay = !$event["end"] || $this->getDateFormatted($event, $end, "Ymd")==
            $this->getDateFormatted($event, $event["start"], "Ymd");
        if (!$sameDay) {
            $text .= " – ".$this->getDateFormatted($event, $end, $formatDate);
            if (!$event["allDay"]) $text .= ", ".$this->getDateFormatted($event, $end, "H:i");
        } elseif (!$event["allDay"] && $event["end"] && $event["end"]!=$event["start"]) {
            $text .= "–".$this->getDateFormatted($event, $end, "H:i");
        }
        return $text;
    }
    
    // Return a timestamp formatted in the time zone of the calendar entry
    public function getDateFormatted($event, $timestamp, $format) {
        try {
            $date = new DateTime("@".$timestamp);
            $date->setTimezone(new DateTimeZone($event["timeZone"]));
        } catch (Exception $exception) {
            return date($format, $timestamp);
        }
        return $date->format($format);
    }
    
    // Return location of a single event, served by this extension
    public function getEventLocation($event) {
        $name = $this->yellow->lookup->normaliseName($event["summary"], true, true, true);
        if (is_string_empty($name)) $name = "termin";
        return $this->yellow->system->get("coreServerBase").$this->yellow->system->get("calendarLocation").
            $event["hash"]."/".$event["start"]."/".$name.".ics";
    }
    
    // Return an event from the cached calendar, null if it is not there
    public function getEventFromCache($hash, $start) {
        $fileName = $this->yellow->system->get("coreExtensionDirectory")."calendar-$hash.cache";
        if (!is_file($fileName)) return null;
        $fileData = $this->yellow->toolbox->readFile($fileName);
        foreach ($this->getEvents($fileData, 0, $hash) as $event) {
            if ($event["start"]==$start) return $event;
        }
        return null;
    }
    
    // Return one event as calendar data
    public function getEventData($event) {
        $format = $event["allDay"] ? "Ymd" : "Ymd\THis\Z";
        $lines = array("BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Datenstrom Yellow//Calendar//EN", "BEGIN:VEVENT");
        $lines[] = "UID:".$event["uid"];
        $lines[] = "DTSTART".($event["allDay"] ? ";VALUE=DATE:" : ":").gmdate($format, $event["start"]);
        if ($event["end"]) $lines[] = "DTEND".($event["allDay"] ? ";VALUE=DATE:" : ":").gmdate($format, $event["end"]);
        $lines[] = "SUMMARY:".$this->getTextEscaped($event["summary"]);
        if (!is_string_empty($event["location"])) $lines[] = "LOCATION:".$this->getTextEscaped($event["location"]);
        if (!is_string_empty($event["description"])) $lines[] = "DESCRIPTION:".$this->getTextEscaped($event["description"]);
        if (!is_string_empty($event["url"])) $lines[] = "URL;VALUE=URI:".$event["url"];
        $lines[] = "END:VEVENT";
        $lines[] = "END:VCALENDAR";
        return implode("\r\n", $lines)."\r\n";
    }
    
    // Return text escaped for a calendar file
    public function getTextEscaped($text) {
        return str_replace(array("\\", "\n", ",", ";"), array("\\\\", "\\n", "\\,", "\;"), $text);
    }
    
    // Return the calendar files behind a link, a Nextcloud share can hold several calendars
    public function getSourceUrls($url) {
        list($server, $token) = $this->getNextcloudShare($url);
        if (!is_string_empty($token)) {
            $urls = array();
            foreach (explode("-", $token) as $part) {
                if (!is_string_empty($part)) $urls[] = "$server/remote.php/dav/public-calendars/$part?export";
            }
            if (!is_array_empty($urls)) return $urls;
        }
        return array($url);
    }
    
    // Return server and token of a Nextcloud calendar share, as a short link or as the link from the app
    public function getNextcloudShare($url) {
        if (preg_match("#^nextcloud://([^/]+)/([^/?\#]+)#", $url, $matches)) {
            return array("https://".$matches[1], $matches[2]);
        }
        if (preg_match("#^(https?://[^/]+)/(?:index\.php/)?apps/calendar/(?:p|embed)/([^/?\#]+)#", $url, $matches)) {
            return array($matches[1], $matches[2]);
        }
        return array("", "");
    }
    
    // Return the link a visitor can open, the calendar in Nextcloud or the calendar file
    public function getLinkUrl($url) {
        list($server, $token) = $this->getNextcloudShare($url);
        if (!is_string_empty($token)) return "$server/apps/calendar/p/$token";
        return preg_replace("/\?export.*$/", "", $url);
    }
    
    // Return the name of a calendar, without the owner Nextcloud appends
    public function getCalendarName($fileData) {
        $name = preg_match("/X-WR-CALNAME:(.*)/", $fileData, $matches) ? trim($matches[1]) : "";
        return trim(preg_replace("/\s*\([^)]*\)$/", "", $name));
    }
    
    // Return the color of a calendar, empty if it has none
    public function getCalendarColor($fileData) {
        if (preg_match("/X-APPLE-CALENDAR-COLOR:\s*(#[0-9a-fA-F]{3,8})/", $fileData, $matches)) {
            return substru($matches[1], 0, 7);
        }
        return "";
    }
    
    // Return the inline style of a calendar color
    public function getColorStyle($color) {
        return "--calendar-color:$color;--calendar-text:".$this->getTextColor($color);
    }
    
    // Return black or white, whichever can be read on a color
    public function getTextColor($color) {
        if (!preg_match("/^#([0-9a-fA-F]{6})$/", $color, $matches)) return "";
        list($red, $green, $blue) = array_map("hexdec", str_split($matches[1], 2));
        return ($red*299 + $green*587 + $blue*114)/1000>150 ? "#000" : "#fff";
    }
    
    // Return the calendars to show, filtered by name
    public function getSourcesSelected($sources, $filter) {
        $names = $this->getFilterNames($filter);
        if (!is_array_empty($names)) {
            $sources = array_values(array_filter($sources, function ($source) use ($names) {
                return $this->isMatchingName($source["name"], $names);
            }));
        }
        return $sources;
    }

    // Return the calendar names of a filter, written as name:one,two
    public function getFilterNames($filter) {
        list($key, $value) = $this->yellow->toolbox->getTextList($filter, ":", 2);
        if ($key!="name" || is_string_empty($value)) return array();
        return array_filter(array_map("trim", explode(",", $value)));
    }

    // Check if a calendar is one of the names of a filter
    public function isMatchingName($name, $names) {
        foreach ($names as $value) {
            if (stristr($name, $value)!==false) return true;
        }
        return false;
    }
    
    // Return the events to show, filtered by calendar name, sorted and limited
    public function getEventsSelected($events, $filter, $entries, $unique = false, $each = 0) {
        $names = $this->getFilterNames($filter);
        if (!is_array_empty($names)) {
            $events = array_filter($events, function ($event) use ($names) {
                return $this->isMatchingName($event["calendar"], $names);
            });
        }
        usort($events, function ($a, $b) { return $a["start"]<=>$b["start"]; });
        if ($unique) {
            $found = array();
            $events = array_filter($events, function ($event) use (&$found) {
                if (isset($found[$event["uid"]])) return false;
                $found[$event["uid"]] = true;
                return true;
            });
        }
        if ($each>0) {
            $found = array();
            $events = array_filter($events, function ($event) use (&$found, $each) {
                $name = $event["calendar"];
                $found[$name] = isset($found[$name]) ? $found[$name]+1 : 1;
                return $found[$name]<=$each;
            });
        }
        return array_slice(array_values($events), 0, $entries>0 ? $entries : count($events));
    }
    
    // Return the short hash of a calendar link, used for cache file and event links
    public function getHash($url) {
        return substru(md5($url), 0, 8);
    }
    
    // Return calendar data, from cache if it is fresh enough
    public function getCalendarData($url, $cacheOnly = false) {
        $fileName = $this->yellow->system->get("coreExtensionDirectory")."calendar-".$this->getHash($url).".cache";
        $cacheTime = intval($this->yellow->system->get("calendarCacheTime"));
        if (is_file($fileName) && ($cacheOnly || filemtime($fileName)+$cacheTime>time())) {
            return $this->yellow->toolbox->readFile($fileName);
        }
        $context = stream_context_create(array("http" => array("timeout" => 5,
            "header" => "User-Agent: Datenstrom Yellow Calendar\r\nAccept: text/calendar\r\n")));
        $fileData = @file_get_contents($url, false, $context);
        if ($fileData===false || strposu($fileData, "BEGIN:VCALENDAR")===false) {
            return is_file($fileName) ? $this->yellow->toolbox->readFile($fileName) : null;
        }
        $this->yellow->toolbox->writeFile($fileName, $fileData);
        return $fileData;
    }
    
    // Return upcoming events, recurring dates included
    public function getEvents($fileData, $entries, $hash = "", $timeFrom = 0) {
        $events = array();
        $calendar = $this->getCalendarName($fileData);
        $color = $this->getCalendarColor($fileData);
        $timeNow = $timeFrom ? $timeFrom : strtotime("today");
        $timeMax = strtotime("+".intval($this->yellow->system->get("calendarMonthsAhead"))." months");
        foreach ($this->getBlocks($fileData) as $block) {
            $event = $this->getEvent($block);
            if (is_null($event)) continue;
            foreach ($this->getEventDates($event, $timeNow, $timeMax) as $start) {
                $entry = $event;
                $entry["end"] = $event["end"] ? $start+($event["end"]-$event["start"]) : 0;
                $entry["start"] = $start;
                $entry["hash"] = $hash;
                $entry["calendar"] = $calendar;
                $entry["color"] = $color;
                $events[] = $entry;
            }
        }
        usort($events, function ($a, $b) { return $a["start"]<=>$b["start"]; });
        return array_slice($events, 0, $entries>0 ? $entries : count($events));
    }
    
    // Return all VEVENT blocks, unfolded
    public function getBlocks($fileData) {
        $fileData = preg_replace("/\r\n[ \t]/", "", str_replace("\n ", "", str_replace("\r\n", "\n", $fileData)));
        $fileData = preg_replace("/\n[ \t]/", "", $fileData);
        preg_match_all("/BEGIN:VEVENT(.*?)END:VEVENT/s", $fileData, $matches);
        return $matches[1];
    }
    
    // Return one event, null if it can not be used
    public function getEvent($block) {
        $event = array("uid" => "", "summary" => "", "location" => "", "description" => "",
            "url" => "", "start" => 0, "end" => 0, "allDay" => false, "calendar" => "", "color" => "",
            "timeZone" => date_default_timezone_get(),
            "rrule" => "", "exdate" => array());
        foreach ($this->yellow->toolbox->getTextLines($block) as $line) {
            if (!preg_match("/^([A-Z\-]+)([^:]*):(.*)$/", trim($line), $matches)) continue;
            list($dummy, $key, $parameters, $value) = $matches;
            if ($key=="UID") $event["uid"] = $value;
            if ($key=="SUMMARY") $event["summary"] = $this->getTextUnescaped($value);
            if ($key=="LOCATION") $event["location"] = $this->getTextUnescaped($value);
            if ($key=="DESCRIPTION") $event["description"] = $this->getTextUnescaped($value);
            if ($key=="RRULE") $event["rrule"] = $value;
            if ($key=="URL" && preg_match("/^https?:\/\//", trim($value))) $event["url"] = trim($value);
            if ($key=="EXDATE") {
                foreach (explode(",", $value) as $date) {
                    $event["exdate"][] = $this->getTimestamp($date, $parameters);
                }
            }
            if ($key=="DTSTART") {
                $event["start"] = $this->getTimestamp($value, $parameters);
                $event["allDay"] = strposu($parameters, "VALUE=DATE")!==false;
                if (preg_match("/TZID=([^;:]+)/", $parameters, $tokens)) $event["timeZone"] = $tokens[1];
            }
            if ($key=="DTEND") $event["end"] = $this->getTimestamp($value, $parameters);
        }
        if (is_string_empty($event["summary"]) || !$event["start"]) return null;
        if (is_string_empty($event["uid"])) $event["uid"] = md5($event["summary"].$event["start"]);
        return $event;
    }
    
    // Return text without calendar escaping
    public function getTextUnescaped($text) {
        return str_replace(array("\\n", "\\,", "\;", "\\\\"), array("\n", ",", ";", "\\"), $text);
    }
    
    // Return timestamp of a calendar date, time zone included
    public function getTimestamp($value, $parameters) {
        $value = trim($value);
        $timeZone = preg_match("/TZID=([^;:]+)/", $parameters, $matches) ? $matches[1] : "UTC";
        if (substru($value, -1, 1)=="Z") $timeZone = "UTC";
        try {
            $date = new DateTime($value, new DateTimeZone($timeZone));
        } catch (Exception $exception) {
            return 0;
        }
        return $date->getTimestamp();
    }
    
    // Return the dates of an event that are still ahead, recurring rules included
    public function getEventDates($event, $timeNow, $timeMax) {
        $dates = array();
        if (is_string_empty($event["rrule"])) {
            if ($event["start"]>=$timeNow) $dates[] = $event["start"];
            return $dates;
        }
        $rule = array();
        foreach (explode(";", $event["rrule"]) as $token) {
            list($key, $value) = $this->yellow->toolbox->getTextList($token, "=", 2);
            $rule[$key] = $value;
        }
        $frequency = isset($rule["FREQ"]) ? $rule["FREQ"] : "";
        $interval = isset($rule["INTERVAL"]) ? max(1, intval($rule["INTERVAL"])) : 1;
        $count = isset($rule["COUNT"]) ? intval($rule["COUNT"]) : 0;
        $until = isset($rule["UNTIL"]) ? $this->getTimestamp($rule["UNTIL"], "") : 0;
        $steps = array("DAILY" => "day", "WEEKLY" => "week", "MONTHLY" => "month", "YEARLY" => "year");
        if (!isset($steps[$frequency])) return $dates;
        $step = "+".$interval." ".$steps[$frequency];
        try {
            $date = new DateTime("@".$event["start"]);
            $date->setTimezone(new DateTimeZone($event["timeZone"]));
        } catch (Exception $exception) {
            return $dates;
        }
        for ($number = 0; $number<500; ++$number) {
            $time = $date->getTimestamp();
            if ($count && $number>=$count) break;
            if ($until && $time>$until) break;
            if ($time>$timeMax) break;
            if ($time>=$timeNow && !in_array($time, $event["exdate"])) $dates[] = $time;
            $date->modify($step);
        }
        return $dates;
    }
    
    // Return error message for authors
    public function getErrorHtml($text) {
        return "<p class=\"error\">Calendar: ".htmlspecialchars($text)."</p>\n";
    }
}
