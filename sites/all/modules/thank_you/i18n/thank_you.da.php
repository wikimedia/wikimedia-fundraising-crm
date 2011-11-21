<?php

$TYmsgs['da'] = array(
	"thank_you_from_name" => "Sue Gardner",
	"thank_you_to_name" => "{contact.display_name}",
	"thank_you_to_name_secondary" => "ven af Wikimedia Foundation",
	"thank_you_subject" => "Tak fra Wikimedia Foundation",
	"thank_you_unsubscribe_title" => "Udmeldelse fra Wikimedia Foundation",
	"thank_you_unsubscribe_button" => "Afmeld",
//	"thank_you_unsubscribe_confirm" => "",
//	"thank_you_unsubscribe_warning" => "",
	"thank_you_unsubscribe_success" => "Du er ikke længere på mailinglisten",
	"thank_you_unsubscribe_delay" => "Der kan gå op til fire dage før ændringerne slår igennem Vi undskylder eventuelle e-breve, du måtte modtage i mellemtiden. Hvis du har spørgsmål, kan du kontakte <a href='mailto:donations@wikimedia.org'>donations@wikimedia.org</a>",
	"thank_you_unsubscribe_fail" => "Der skete en fejl under behandling af forespørgslen. Kontakt venligst <a href='mailto:donations@wikimedia.org'>donations@wikimedia.org</a>.",
);
$TYmsgs['da']['thank_you_body_plaintext'] =<<<'EOD'
Kære {contact.first_name},

Du er en knag! Mange tak for din donation til Wikimedia Foundation.

Det er sådan vi kan betale vores regninger - med hjælp fra folk som dig, der giver 25, 100 eller måske 500 kroner. Mit yndlingsbidrag sidste år var fem pund fra en lille pige i England, der havde overtalt sine forældre til at lade hende donere sine lommepenge. Det er folk som dig og den pige, der gør det muligt for Wikipedia at fortsætte med at udbyde fri og let adgang til objektiv viden for alle i verden. Til alle der hjælper med at betale for det, og til dem der ikke har råd til at bidrage. Mange tak skal i have.

Jeg ved godt at det er nemt at ignorere vores støtteopfordringer, og jeg er glad for at du ikke gjorde det. Fra mig, og de titusindvis af frivillige, der skriver på Wikipedia, skal du have en tak for at hjælpe os med at gøre verden til et bedre sted. Vi vil bruge dine penge med omhu og jeg takker dig for at du har vist os den tillid.

Mange tak,
Sue Gardner
Wikimedia Foundation Executive Director

---

Til regnskabet: Dit bidrag d. {contribution.date} var på {contribution.source}.
Denne henvendelse gælder som kvittering for dit bidrag. Ingen varer eller serviceydelser blev modregnet dette bidrag. Wikimedia Foundation, Inc. er en non-profit organisation med 501(c)(3) skattefritagelsesstatus i USA. Vores adresse er 149 New Montgomery, 3rd Floor, San Francisco, CA, 94105. U.S. tax-exempt number: 20-0049703

---

Mulighed for fravalg:
Som bidragsyder vil vi gerne kunne gerne sende dig nyheder om brugeraktiviteter og indsamlinger. Hvis du ikke ønsker at modtage sådanne email fra os, så klik venligst herunder for at blive taget af listen:

{unsubscribe_link}
EOD;

$TYmsgs['da']['thank_you_body_html'] =<<<'EOD'
<p>Kære {contact.first_name},</p>

<p>Du er en knag! Mange tak for din donation til Wikimedia Foundation.</p>

<p>Det er sådan vi kan betale vores regninger - med hjælp fra folk som dig, der giver 25, 100 eller måske 500 kroner. Mit yndlingsbidrag sidste år var fem pund fra en lille pige i England, der havde overtalt sine forældre til at lade hende donere sine lommepenge. Det er folk som dig og den pige, der gør det muligt for Wikipedia at fortsætte med at udbyde fri og let adgang til objektiv viden for alle i verden. Til alle der hjælper med at betale for det, og til dem der ikke har råd til at bidrage. Mange tak skal i have.</p>

<p>Jeg ved godt at det er nemt at ignorere vores støtteopfordringer, og jeg er glad for at du ikke gjorde det. Fra mig, og de titusindvis af frivillige, der skriver på Wikipedia, skal du have en tak for at hjælpe os med at gøre verden til et bedre sted. Vi vil bruge dine penge med omhu og jeg takker dig for at du har vist os den tillid.</p>

<p>Mange tak,<br />
<b>Sue Gardner</b><br />
Wikimedia Foundation Executive Director</p>

<p>Til regnskabet: Dit bidrag d. {contribution.date} var på {contribution.source}.</p>
<p>Denne henvendelse gælder som kvittering for dit bidrag. Ingen varer eller serviceydelser blev modregnet dette bidrag. Wikimedia Foundation, Inc. er en non-profit organisation med 501(c)(3) skattefritagelsesstatus i USA. Vores adresse er 149 New Montgomery, 3rd Floor, San Francisco, CA, 94105. U.S. tax-exempt number: 20-0049703</p>

<div style="padding:0 10px 5px 10px; border:1px solid black;">
<p><i>Mulighed for fravalg:</i></p>
<p>Som bidragsyder vil vi gerne kunne gerne sende dig nyheder om brugeraktiviteter og indsamlinger. Hvis du ikke ønsker at modtage sådanne email fra os, så klik venligst herunder for at blive taget af listen:</p>
<a style="padding-left: 25px;" href="{unsubscribe_link}">Afmeld</a>
</div>
EOD;


