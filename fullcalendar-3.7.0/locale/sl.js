!function(e){"function"==typeof define&&define.amd?define(["jquery","moment"],e):"object"==typeof exports?module.exports=e(require("jquery"),require("moment")):e(window.jQuery,window.moment)}(function(e,n){!function(){function e(e,n,a,t){var r=e+" ";switch(a){case"s":return n||t?"nekaj sekund":"nekaj sekundami";case"m":return n?"ena minuta":"eno minuto";case"mm":return r+=1===e?n?"minuta":"minuto":2===e?n||t?"minuti":"minutama":e<5?n||t?"minute":"minutami":n||t?"minut":"minutami";case"h":return n?"ena ura":"eno uro";case"hh":return r+=1===e?n?"ura":"uro":2===e?n||t?"uri":"urama":e<5?n||t?"ure":"urami":n||t?"ur":"urami";case"d":return n||t?"en dan":"enim dnem";case"dd":return r+=1===e?n||t?"dan":"dnem":2===e?n||t?"dni":"dnevoma":n||t?"dni":"dnevi";case"M":return n||t?"en mesec":"enim mesecem";case"MM":return r+=1===e?n||t?"mesec":"mesecem":2===e?n||t?"meseca":"mesecema":e<5?n||t?"mesece":"meseci":n||t?"mesecev":"meseci";case"y":return n||t?"eno leto":"enim letom";case"yy":return r+=1===e?n||t?"leto":"letom":2===e?n||t?"leti":"letoma":e<5?n||t?"leta":"leti":n||t?"let":"leti"}}n.defineLocale("sl",{months:"januar_februar_marec_april_maj_junij_julij_avgust_september_oktober_november_december".split("_"),monthsShort:"jan._feb._mar._apr._maj._jun._jul._avg._sep._okt._nov._dec.".split("_"),monthsParseExact:!0,weekdays:"nedelja_ponedeljek_torek_sreda_četrtek_petek_sobota".split("_"),weekdaysShort:"ned._pon._tor._sre._čet._pet._sob.".split("_"),weekdaysMin:"ne_po_to_sr_če_pe_so".split("_"),weekdaysParseExact:!0,longDateFormat:{LT:"H:mm",LTS:"H:mm:ss",L:"DD.MM.YYYY",LL:"D. MMMM YYYY",LLL:"D. MMMM YYYY H:mm",LLLL:"dddd, D. MMMM YYYY H:mm"},calendar:{sameDay:"[danes ob] LT",nextDay:"[jutri ob] LT",nextWeek:function(){switch(this.day()){case 0:return"[v] [nedeljo] [ob] LT";case 3:return"[v] [sredo] [ob] LT";case 6:return"[v] [soboto] [ob] LT";case 1:case 2:case 4:case 5:return"[v] dddd [ob] LT"}},lastDay:"[včeraj ob] LT",lastWeek:function(){switch(this.day()){case 0:return"[prejšnjo] [nedeljo] [ob] LT";case 3:return"[prejšnjo] [sredo] [ob] LT";case 6:return"[prejšnjo] [soboto] [ob] LT";case 1:case 2:case 4:case 5:return"[prejšnji] dddd [ob] LT"}},sameElse:"L"},relativeTime:{future:"čez %s",past:"pred %s",s:e,m:e,mm:e,h:e,hh:e,d:e,dd:e,M:e,MM:e,y:e,yy:e},dayOfMonthOrdinalParse:/\d{1,2}\./,ordinal:"%d.",week:{dow:1,doy:7}})}(),e.fullCalendar.locale("sl",{buttonText:{month:"Mesec",week:"Teden",day:"Dan",list:"Dnevni red"},allDayText:"Ves dan",eventLimitText:"več",noEventsMessage:"Ni dogodkov za prikaz"})});