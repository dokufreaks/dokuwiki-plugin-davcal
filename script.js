/* DOKUWIKI:include_once fullcalendar-3.10.5/moment.js */
/* DOKUWIKI:include_once fullcalendar-3.10.5/fullcalendar.js */
/* DOKUWIKI:include_once fullcalendar-3.10.5/locale/de.js */
/* DOKUWIKI:include_once fullcalendar-3.10.5/locale/en.js */
/* DOKUWIKI:include_once fullcalendar-3.10.5/locale/fr.js */
/* DOKUWIKI:include_once fullcalendar-3.10.5/locale/nl.js */
/* DOKUWIKI:include_once fullcalendar-3.10.5/locale/ru.js */
/* DOKUWIKI:include_once datetimepicker-2.4.5/jquery.datetimepicker.js */
/* DOKUWIKI:include_once jstz.js */

/**
 * Initialize the DAVCal script, attaching some event handlers and triggering
 * the initial load of the fullcalendar JS
 */
jQuery(function() {
    // Make description textareas full width in dialogs
    jQuery('<style>')
        .text('.dw_davcal__text, textarea.dw_davcal__editevent, textarea.dw_davcal__appearance { width: 100%; box-sizing: border-box; }')
        .appendTo('head');

    // Redefine functions for using moment.js with datetimepicker
    
    Date.parseDate = function( input, format ){
      return moment(input,format).toDate();
    };
    Date.prototype.dateFormat = function( format ){
      return moment(this).format(format);
    };
    
    // Attach to event links
    var calendarpage = jQuery('#fullCalendar').data('calendarpage');
    if(!calendarpage) return;
    dw_davcal__modals.page = calendarpage;

    // jQuery UI closes a dialog on Escape and on the titlebar X without going
    // through our button handlers, which used to leave the module's dialog
    // reference set. The show* functions bail out early when that reference is
    // non-null, so the calendar stopped responding to clicks entirely. Catch
    // the widget's own close event and run the same teardown.
    jQuery(document).on('dialogclose', function(e) {
        var wrapperId = jQuery(e.target).closest('.ui-dialog').attr('id');
        if(wrapperId === 'dw_davcal__edit')
            dw_davcal__modals.hideEditEventDialog();
        else if(wrapperId === 'dw_davcal__settings')
            dw_davcal__modals.hideSettingsDialog();
        else if(wrapperId === 'dw_davcal__confirm')
            dw_davcal__modals.hideDialog();
    });
    
    jQuery('div.fullCalendarSettings a').each(function() {
        var $link = jQuery(this);
        var href = $link.attr('href');
        if (!href) return;

        $link.click(
            function(e) {
                dw_davcal__modals.showSettingsDialog();
                e.preventDefault();
                return '';
            }
        );
        }
    );
    
    // First, retrieve the current settings.
    // Upon success, initialize fullcalendar.
    var postArray = { };
    jQuery.post(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_davcal',
            id: dw_davcal__modals.page,
            page: dw_davcal__modals.page,
            action: 'getSettings',
            params: postArray,
            sectok: JSINFO.plugin.davcal['sectok']
        },
        function(data)
        {
            var result = data['result'];
            if(result === true)
            {
                dw_davcal__modals.settings = data['settings'];
                var tz = false;
                if(data['settings']['timezone'] !== '')
                    tz = data['settings']['timezone'];
                // Force-overwrite thhe timezone setting if requested
                if(data['settings']['meta']['forcetimezone'] !== 'no')
                    tz = data['settings']['meta']['forcetimezone'];
                var fcOptions = {
                    dayClick: function(date, jsEvent, view) {
                        if(dw_davcal__modals.page === 'calendars:appearances_calendar')
                            dw_davcal__modals.showAppearancesDialog(date, false);
                        else
                            dw_davcal__modals.showEditEventDialog(date, false);
                    },
                    eventClick: function(calEvent, jsEvent, view) {
                        if(calEvent.page === 'calendars:appearances_calendar')
                            dw_davcal__modals.showAppearancesDialog(calEvent, true);
                        else
                            dw_davcal__modals.showEditEventDialog(calEvent, true);
                    },
                    events: {
                        url: DOKU_BASE + 'lib/exe/ajax.php',
                        type: 'POST',
                        data: {
                            call: 'plugin_davcal',
                            action: 'getEvents',
                            id: dw_davcal__modals.page,
                            page: dw_davcal__modals.page,
                            sectok: JSINFO.plugin.davcal['sectok']
                        },
                        error: function() {
                            dw_davcal__modals.msg = LANG.plugins.davcal['error_retrieving_data'];
                            dw_davcal__modals.showDialog(false);
                        }
                    },
                    header: {
                        left: 'title',
                        center: 'today prev,next',
                        right: 'month,agendaWeek,agendaDay,listWeek'
                    },
                    locale: JSINFO.plugin.davcal['language'],
                    weekNumbers: (data['settings']['weeknumbers'] == 1) ? true : false,
                    timezone: tz,
                    weekends: (data['settings']['workweek'] == 1) ? false : true,
                    firstDay: (data['settings']['monday'] == 1) ? 1 : 0,
                    defaultView: data['settings']['meta']['view'],
                    eventRender: function(calEvent, element) {
                        if(calEvent.members && calEvent.members != 0 && calEvent.page === 'calendars:appearances_calendar') {
                            var $title = element.find('.fc-title');
                            var $listTitle = element.find('.fc-list-item-title');
                            if($title.length) {
                                $title.after('<div class="dw_davcal__members_badge" style="font-size:0.8em;color:#fff;opacity:0.85;margin-top:2px;">★ Members are going!</div>');
                            } else if($listTitle.length) {
                                $listTitle.append('<div class="dw_davcal__members_badge" style="font-size:0.85em;color:#555;margin-top:2px;">★ Members are going!</div>');
                            }
                        }
                    },
                };
                var timeformat = data['settings']['timeformat'];
                // Force-overwrite the user's timezone setting if requested by the calendar
                if(data['settings']['meta']['forcetimeformat'] !== 'no')
                    timeformat = data['settings']['meta']['forcetimeformat'];
                if(timeformat !== 'lang')
                {
                    // If the time format is language-based, we don't need to pass
                    // the timeFormat option to fullCalendar
                    if(timeformat == '24h')
                    {
                        fcOptions.timeFormat = 'H:mm';
                    }
                    if(timeformat == '12h')
                    {
                        fcOptions.timeFormat = 'h:mmt';
                    }
                }
                for (var key in data['settings']['meta']['fcoptions'])
                {
                    if(!data['settings']['meta']['fcoptions'].hasOwnProperty(key)) continue;
                    
                    var val = data['settings']['meta']['fcoptions'][key];
                    fcOptions[key] = val;
                }
                var detectedTz = jstz.determine().name();
                dw_davcal__modals.detectedTz = detectedTz;
                // The current TZ value holds either the uers's selection or
                // the force timezone value
                dw_davcal__modals.currentTz = (tz === false) ? '' : tz;
                // Initialize the davcal popup
                var res = jQuery('#fullCalendar').fullCalendar(fcOptions);
            }
        }
    );    
});

/**
 * This holds all modal windows that DAVCal uses.
 */
var dw_davcal__modals = {
    $editEventDialog: null,
    $dialog: null,
    $settingsDialog: null,
    $inputDialog: null,
    msg: null,
    completeCb: null,
    action: null,
    uid: null,
    settings: null,
    page: null,
    detectedTz: null,
    currentTz: null,
    
    /**
     * Show the settings dialog
     */
    // FIXME: Hide URLs for multi-calendar
    showSettingsDialog : function() {
        if(dw_davcal__modals.$settingsDialog)
            return;

        // Dialog buttons are language-dependent and defined here.
        // Attach event handlers for save and cancel.
        var dialogButtons = {};
        if(!JSINFO.plugin.davcal['disable_settings'])
        {
            dialogButtons[LANG.plugins.davcal['save']] = function() {
                var postArray = { };
                jQuery("input[class=dw_davcal__settings], select[class=dw_davcal__settings]").each(function() {
                  if(jQuery(this).attr('type') == 'checkbox')
                  {
                      postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
                  }
                  else
                  {
                      postArray[jQuery(this).prop('name')] = jQuery(this).val();
                  }
                });
                jQuery('#dw_davcal__ajaxsettings').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_davcal',
                        id: dw_davcal__modals.page,
                        page: dw_davcal__modals.page,
                        action: 'saveSettings',
                        params: postArray,
                        sectok: JSINFO.plugin.davcal['sectok']
                    },
                    function(data)
                    {
                        var result = data['result'];
                        var html = data['html'];
                        jQuery('#dw_davcal__ajaxsettings').html(html);
                        if(result === true)
                        {
                            location.reload();
                        }
                    }
                );
            };
        }
        dialogButtons[LANG.plugins.davcal['cancel']] = function () {
            dw_davcal__modals.hideSettingsDialog();
        };
        
        var settingsHtml = '<div><table>';
        
        if(JSINFO.plugin.davcal['disable_settings'] && JSINFO.plugin.davcal['disable_sync'] && JSINFO.plugin.davcal['disable_ics'])
        {
            settingsHtml += LANG.plugins.davcal['nothing_to_show'];
        }
        
        if(!JSINFO.plugin.davcal['disable_settings'])
        {
            settingsHtml += '<tr><td>' + LANG.plugins.davcal['timezone'] + '</td><td><select name="timezone" id="dw_davcal__settings_timezone" class="dw_davcal__settings"></select></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['timeformat'] + '</td><td><select name="timeformat" id="dw_davcal__settings_timeformat" class="dw_davcal__settings"></select></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['weeknumbers'] + '</td><td><input type="checkbox" name="weeknumbers" id="dw_davcal__settings_weeknumbers" class="dw_davcal__settings"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['only_workweek'] + '</td><td><input type="checkbox" name="workweek" id="dw_davcal__settings_workweek" class="dw_davcal__settings"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['start_monday'] + '</td><td><input type="checkbox" name="monday" id="dw_davcal__settings_monday" class="dw_davcal__settings"></td></tr>';
         }
         
         if(!JSINFO.plugin.davcal['disable_sync'])
         {
             settingsHtml += '<tr id="dw_davcal__settings_syncurl"><td>' + LANG.plugins.davcal['sync_url'] + '</td><td><input type="text" name="syncurl" readonly="readonly" id="dw_davcal__settings_syncurl_edit" class="dw_davcal__text" value="' + dw_davcal__modals.settings['syncurl'] + '"></td></tr>';
             settingsHtml += '<tr id="dw_davcal__settings_principalurl"><td>' + LANG.plugins.davcal['sync_ical'] + '</td><td><input type="text" name="principalurl" readonly="readonly" id="dw_davcal__settings_principalurl_edit" class="dw_davcal__text" value="' + dw_davcal__modals.settings['principalurl'] + '"></td></tr>';
         }
         
         if(!JSINFO.plugin.davcal['disable_ics'])
         {
             settingsHtml += '<tr id="dw_davcal__settings_privateurl"><td>' + LANG.plugins.davcal['private_url'] + '</td><td><input type="text" name="privateurl" readonly="readonly" id="dw_davcal__settings_privateurl_edit" class="dw_davcal__text" value="' + dw_davcal__modals.settings['privateurl'] + '"></td></tr>';
         }
         
         settingsHtml += '</table>' +
            '</div>' +
            '<div id="dw_davcal__ajaxsettings"></div>';
        
        dw_davcal__modals.$settingsDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: LANG.plugins.davcal['settings'],
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
           settingsHtml
            )
       .parent()
       .attr('id','dw_davcal__settings')
       .show()
       .appendTo('.dokuwiki:first');
       
       jQuery('#dw_davcal__settings').position({
           my: "center",
           at: "center",
           of: window
       });
       
       // Initialize current settings
        
        if(!JSINFO.plugin.davcal['disable_settings'])
        {
            var $tzdropdown = jQuery('#dw_davcal__settings_timezone');
            jQuery('#fullCalendarTimezoneList option').each(function() {
                jQuery('<option />', {value: jQuery(this).val(), 
                        text: jQuery(this).text()}).appendTo($tzdropdown);
            });
            
            var $tfdropdown = jQuery('#dw_davcal__settings_timeformat');
            jQuery('<option />', {value: 'lang', text: LANG.plugins.davcal['language_specific']}).appendTo($tfdropdown);
            jQuery('<option />', {value: '24h', text: '24h'}).appendTo($tfdropdown);
            jQuery('<option />', {value: '12h', text: '12h'}).appendTo($tfdropdown);
            
            if(!JSINFO.plugin.davcal['disable_sync'])
            {
                jQuery('#dw_davcal__settings_syncurl_edit').on('click', function() {
                    jQuery(this).select();
                });
                jQuery('#dw_davcal__settings_principalurl_edit').on('click', function() {
                    jQuery(this).select();
                });
            }
            
            if(!JSINFO.plugin.davcal['disable_ics'])
            {
                jQuery('#dw_davcal__settings_privateurl_edit').on('click', function() {
                    jQuery(this).select(); 
                });
            }
            
            if(dw_davcal__modals.settings)
            {
                if(dw_davcal__modals.settings['timeformat'] !== '')
                    jQuery('#dw_davcal__settings_timeformat').val(dw_davcal__modals.settings['timeformat']);
                if(dw_davcal__modals.settings['timezone'] !== '')
                    jQuery('#dw_davcal__settings_timezone').val(dw_davcal__modals.settings['timezone']);
                if(dw_davcal__modals.settings['weeknumbers'] == 1)
                    jQuery('#dw_davcal__settings_weeknumbers').prop('checked', true);
                else
                    jQuery('#dw_davcal__settings_weeknumbers').prop('checked', false);
                    
                if(dw_davcal__modals.settings['workweek'] == 1)
                    jQuery('#dw_davcal__settings_workweek').prop('checked', true);
                else
                    jQuery('#dw_davcal__settings_workweek').prop('checked', false);
                    
                if(dw_davcal__modals.settings['monday'] == 1)
                    jQuery('#dw_davcal__settings_monday').prop('checked', true);
                else
                    jQuery('#dw_davcal__settings_monday').prop('checked', false);
                if(dw_davcal__modals.settings['meta']['forcetimezone'] !== 'no')
                    jQuery('#dw_davcal__settings_timezone').prop('disabled', true);
                if(dw_davcal__modals.settings['meta']['forcetimeformat'] !== 'no')
                    jQuery('#dw_davcal__settings_timeformat').prop('disabled', true);
            }
        }

        // attach event handlers
        jQuery('#dw_davcal__settings .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideSettingsDialog();
        });
    },
    
    /**
     * Sanity-check our events.
     * 
     * @return boolean false on failure, otherwise true
     */
    checkEvents : function() {
        // Retrieve dates
        var allDay = jQuery('#dw_davcal__allday_edit').prop('checked');
        var startDate = moment(jQuery('#dw_davcal__eventfrom_edit').val(), 'YYYY-MM-DD');
        var endDate = moment(jQuery('#dw_davcal__eventto_edit').val(), 'YYYY-MM-DD');
        
        // Do the checking
        if(!allDay)
        {
            var startTime = moment.duration(jQuery('#dw_davcal__eventfromtime_edit').val());
            var endTime = moment.duration(jQuery('#dw_davcal__eventtotime_edit').val());
            startDate.add(startTime);
            endDate.add(endTime);
        }
        if(!startDate.isValid())
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['start_date_invalid'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        if(!endDate.isValid())
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['end_date_invalid'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        if(endDate.isBefore(startDate))
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['end_date_before_start_date'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        if(!allDay && endDate.isSame(startDate))
        {
            dw_davcal__modals.msg = LANG.plugins.davcal['end_date_is_same_as_start_date'];
            dw_davcal__modals.showDialog(false);
            return false;
        }
        return true;
    },

    /**
     * Show a choice dialog for recurring events asking whether to edit
     * all occurrences or just this one.
     * @param {Object} event The calendar event that was clicked
     */
    showRecurringChoiceDialog: function(event) {
        var choiceButtons = {};
        choiceButtons['Edit all occurrences'] = function() {
            jQuery(this).dialog('destroy').remove();
            event._recurringMode = 'all';
            dw_davcal__modals.showEditEventDialog(event, true);
        };
        choiceButtons['Edit this occurrence only'] = function() {
            jQuery(this).dialog('destroy').remove();
            event._recurringMode = 'single';
            // An occurrence that was already moved carries the RECURRENCE-ID of
            // the slot it replaces. Key off that, not off its current start, or
            // the save writes an override for a date the RRULE never generates
            // and the occurrence gets drawn twice.
            event._occurrenceDate = event.recurrenceid || event.start.toISOString();
            dw_davcal__modals.showEditEventDialog(event, true);
        };
        choiceButtons['Cancel'] = function() {
            jQuery(this).dialog('destroy').remove();
        };
        jQuery(document.createElement('div'))
            .dialog({
                autoOpen: true,
                draggable: true,
                title: 'Edit recurring event',
                resizable: false,
                buttons: choiceButtons,
            })
            .html('<p>This is a recurring event. Do you want to edit all occurrences or just this one?</p>')
            .parent()
            .show()
            .appendTo('.dokuwiki:first');
    },

    /**
     * Render "Add to Calendar" links for Google, Outlook 365, Outlook Live,
     * iCalendar, and a generic ICS download.
     */
    renderAddToCalendarLinks: function(calEvent) {
        var title = encodeURIComponent(calEvent.title || '');
        var description = encodeURIComponent(calEvent.description || '');
        var location = encodeURIComponent(calEvent.location || '');
        var uid = calEvent.id || '';

        function toCalDate(momentObj, allDay) {
            if(allDay)
                return momentObj.format('YYYYMMDD');
            return momentObj.utc().format('YYYYMMDDTHHmmss') + 'Z';
        }

        var start = toCalDate(calEvent.start, calEvent.allDay);
        var end = calEvent.end ? toCalDate(calEvent.end, calEvent.allDay) : start;
        var occurrenceDate = calEvent.start.format('YYYY-MM-DD');

        // Google Calendar
        var googleUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE' +
            '&text=' + title +
            '&dates=' + start + '/' + end +
            '&details=' + description +
            '&location=' + location;

        // Outlook 365
        var outlook365Url = 'https://outlook.office.com/calendar/0/deeplink/compose?subject=' + title +
            '&startdt=' + (calEvent.allDay ? calEvent.start.format('YYYY-MM-DD') : calEvent.start.utc().format()) +
            '&enddt=' + (calEvent.end ? (calEvent.allDay ? calEvent.end.format('YYYY-MM-DD') : calEvent.end.utc().format()) : '') +
            '&body=' + description +
            '&location=' + location;

        // Outlook Live
        var outlookLiveUrl = 'https://outlook.live.com/calendar/0/deeplink/compose?subject=' + title +
            '&startdt=' + (calEvent.allDay ? calEvent.start.format('YYYY-MM-DD') : calEvent.start.utc().format()) +
            '&enddt=' + (calEvent.end ? (calEvent.allDay ? calEvent.end.format('YYYY-MM-DD') : calEvent.end.utc().format()) : '') +
            '&body=' + description +
            '&location=' + location;

        // ICS download URL
        var icsUrl = DOKU_BASE + 'lib/plugins/davcal/singleevent.php?uid=' + encodeURIComponent(uid) +
            '&occurrence_date=' + encodeURIComponent(occurrenceDate);

        var html =
            '<select id="dw_davcal__addtocal_select" style="margin-right:6px;">' +
            '<option value="">-- Select --</option>' +
            '<option value="' + googleUrl + '">Google Calendar</option>' +
            '<option value="' + outlook365Url + '">Outlook 365</option>' +
            '<option value="' + outlookLiveUrl + '">Outlook Live</option>' +
            '<option value="' + icsUrl + '">iCalendar</option>' +
            '<option value="' + icsUrl + '_download">Other (.ics)</option>' +
            '</select>';

        jQuery('#dw_davcal__addtocal_links').html(html);

        jQuery('#dw_davcal__addtocal_select').on('change', function() {
            var url = jQuery(this).val();
            if(!url) return;
            if(url.endsWith('_download')) {
                window.location.href = url.replace('_download', '');
            } else {
                window.open(url, '_blank');
            }
            jQuery(this).val('');
        });
    },

    /**
     * Check if a wiki page exists for the given event and update the
     * Create/View Event Page button accordingly.
     * @param {Object} calEvent The calendar event
     */
    checkAndRenderEventPageButton: function(calEvent) {
        var pageid = calEvent.page;
        var title = calEvent.title;
        var date = calEvent.start.format('YYYY-MM-DD');

        jQuery('#dw_davcal__eventpage_btn').html('<em>Checking...</em>');

        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_davcal',
                id: pageid,
                page: dw_davcal__modals.page,
                action: 'checkEventPage',
                params: { title: title, date: date },
                sectok: JSINFO.plugin.davcal['sectok']
            },
            function(data) {
                if(data['result'] !== true) {
                    jQuery('#dw_davcal__eventpage_row').hide();
                    return;
                }
                jQuery('#dw_davcal__eventpage_row').show();
                if(data['exists']) {
                    jQuery('#dw_davcal__eventpage_btn').html(
                        '<a href="' + data['url'] + '" target="_blank">View Event Page</a>'
                    );
                } else {
                    jQuery('#dw_davcal__eventpage_btn').html(
                        '<a href="#" id="dw_davcal__create_eventpage">Create Event Page</a>'
                    );
                    jQuery('#dw_davcal__create_eventpage').on('click', function(e) {
                        e.preventDefault();
                        jQuery('#dw_davcal__eventpage_btn').html('<em>Creating...</em>');

                        // Collect attachments
                        var links = [];
                        jQuery('.dw_davcal__editevent_attachment_link').each(function() {
                            var href = jQuery(this).attr('href');
                            if(href) links.push(href);
                        });

                        jQuery.post(
                            DOKU_BASE + 'lib/exe/ajax.php',
                            {
                                call: 'plugin_davcal',
                                id: pageid,
                                page: dw_davcal__modals.page,
                                action: 'createEventPage',
                                params: {
                                    title: title,
                                    date: date,
                                    time: calEvent.allDay ? '' : calEvent.start.format('HH:mm'),
                                    description: calEvent.description || '',
                                    location: calEvent.location || '',
                                    recurrence: calEvent.recurrence || '',
                                    recurrence_byday: calEvent.recurrence_byday || '',
                                    recurrence_bysetpos: calEvent.recurrence_bysetpos || '',
                                    recurrence_weekday: calEvent.recurrence_weekday || '',
                                    links: links,
                                    uid: calEvent.id || ''
                                },
                                sectok: JSINFO.plugin.davcal['sectok']
                            },
                            function(data) {
                                if(data['result'] === true) {
                                    jQuery('#dw_davcal__eventpage_btn').html(
                                        '<a href="' + data['url'] + '" target="_blank">View Event Page</a>'
                                    );
                                    window.open(data['url'], '_blank');
                                } else {
                                    jQuery('#dw_davcal__eventpage_btn').html('<em>Error creating page.</em>');
                                }
                            }
                        );
                    });
                }
            }
        );
    },

    /**
     * Show the appearances-specific event dialog
     */
    showAppearancesDialog : function(event, edit) {
        if(dw_davcal__modals.$editEventDialog)
            return;

        var readonly = true;
        for(var i=0; i<dw_davcal__modals.settings['calids'].length; i++) {
            if(edit) {
                if(event.page == dw_davcal__modals.settings['calids'][i]['page'])
                    readonly = !dw_davcal__modals.settings['calids'][i]['write'];
            } else {
                if(dw_davcal__modals.settings['calids'][i]['write'])
                    readonly = false;
            }
        }

        var calEvent = {};
        var title = edit ? 'Edit Appearance' : 'Add Appearance';
        var dialogButtons = {};
        var pageid = 'calendars:appearances_calendar';

        if(!edit) {
            calEvent.start = event;
            calEvent.end = moment(event);
            calEvent.start.hour(0);  calEvent.start.minute(0);
            calEvent.end.hour(0);    calEvent.end.minute(0);
            calEvent.allDay = true;
            calEvent.title = '';
            calEvent.description = '';
            calEvent.location = '';
            calEvent.id = '0';
            calEvent.page = pageid;
            calEvent.members = '0';
        } else {
            calEvent = event;
        }

        if(!readonly) {
            var saveAction = edit ? 'editEvent' : 'newEvent';
            var saveLabel = edit ? LANG.plugins.davcal['edit'] : LANG.plugins.davcal['create'];
            dialogButtons[saveLabel] = function() {
                var postArray = {};
                // Force allday and public
                postArray['allday'] = 1;
                postArray['public'] = 1;
                // Collect fields
                jQuery('input.dw_davcal__appearance, textarea.dw_davcal__appearance, select.dw_davcal__appearance').each(function() {
                    if(jQuery(this).attr('type') === 'checkbox')
                        postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
                    else
                        postArray[jQuery(this).prop('name')] = jQuery(this).val();
                });
                // Submitter attending
                postArray['submitter_attending'] = jQuery('#dw_davcal__submitter_attending').prop('checked') ? 1 : 0;
                postArray['submitter_name']   = jQuery('#dw_davcal__submitter_name').val();
                postArray['submitter_table']  = jQuery('#dw_davcal__submitter_table').val();
                postArray['submitter_panels'] = jQuery('#dw_davcal__submitter_panels').val();
                postArray['submitter_stamprally'] = jQuery('#dw_davcal__submitter_stamprally').prop('checked') ? 1 : 0;
                // For new events, set members flag from checkbox. For edits, preserve existing flag.
                postArray['members'] = edit ? (calEvent.members == 1 ? 1 : 0) : postArray['submitter_attending'];
                // Links
                var attachArr = [];
                jQuery('.dw_davcal__editevent_attachment_link').each(function() {
                    var a = jQuery(this).attr('href');
                    if(a) attachArr.push(a);
                });
                postArray['attachments'] = attachArr;

                jQuery('#dw_davcal__ajaxedit').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_davcal',
                        id: pageid,
                        page: dw_davcal__modals.page,
                        action: saveAction,
                        params: postArray,
                        sectok: JSINFO.plugin.davcal['sectok']
                    },
                    function(data) {
                        if(data['result'] === true) {
                            jQuery('#fullCalendar').fullCalendar('refetchEvents');
                            dw_davcal__modals.hideEditEventDialog();
                            // Open auto-created event page if returned
                            if(data['eventpage_url']) {
                                window.open(data['eventpage_url'], '_blank');
                            }
                        } else {
                            jQuery('#dw_davcal__ajaxedit').html(data['html']);
                        }
                    }
                );
            };

            if(edit) {
                dialogButtons[LANG.plugins.davcal['delete']] = function() {
                    dw_davcal__modals.action = 'deleteEvent';
                    dw_davcal__modals.msg = LANG.plugins.davcal['really_delete_this_event'];
                    dw_davcal__modals.completeCb = function(data) {
                        if(data['result'] === true) {
                            jQuery('#fullCalendar').fullCalendar('refetchEvents');
                            dw_davcal__modals.hideEditEventDialog();
                        }
                    };
                    // Pass event details so ajax.php can delete the wiki page
                    var delExtra = {
                        pageid: pageid,
                        uid: calEvent.id || '',
                        eventname: calEvent.title || '',
                        eventfrom: calEvent.start ? calEvent.start.format('YYYY-MM-DD') : ''
                    };
                    jQuery.extend(dw_davcal__modals, { _deleteExtra: delExtra });
                    dw_davcal__modals.showDialog(true);
                };
            }
        }

        dialogButtons[LANG.plugins.davcal['cancel']] = function() {
            dw_davcal__modals.hideEditEventDialog();
        };

        dw_davcal__modals.uid = calEvent.id;

        // Build submitter section HTML — only for new events
        var submitterHtml = edit ? '' :
            '<tr><td colspan="2"><label><input type="checkbox" id="dw_davcal__submitter_attending"> Will you be at this event?</label></td></tr>' +
            '<tr id="dw_davcal__submitter_fields" style="display:none"><td colspan="2"><table>' +
            '<tr><td>Your Name</td><td><input type="text" id="dw_davcal__submitter_name"></td></tr>' +
            '<tr><td>Table Number</td><td><input type="text" id="dw_davcal__submitter_table"></td></tr>' +
            '<tr><td colspan="2"><label><input type="checkbox" id="dw_davcal__submitter_panels_check"> Participating in panels/workshops?</label></td></tr>' +
            '<tr id="dw_davcal__submitter_panels_row" style="display:none"><td>Details</td><td><textarea id="dw_davcal__submitter_panels"></textarea></td></tr>' +
            '<tr><td colspan="2"><label><input type="checkbox" id="dw_davcal__submitter_stamprally"> Participate in stamp rally?</label></td></tr>' +
            '</table></td></tr>';

        dw_davcal__modals.$editEventDialog = jQuery(document.createElement('div'))
        .dialog({
            autoOpen: false,
            draggable: true,
            title: title,
            resizable: true,
            buttons: dialogButtons,
        })
        .html(
            '<div><table>' +
            '<tr><td>Event Name</td><td><input type="text" name="eventname" class="dw_davcal__appearance" id="dw_davcal__appearance_name"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['description'] + '</td><td><textarea name="eventdescription" class="dw_davcal__appearance dw_davcal__text" id="dw_davcal__appearance_desc"></textarea></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['location'] + '</td><td><input type="text" name="eventlocation" class="dw_davcal__appearance" id="dw_davcal__appearance_location"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['from'] + '</td><td><input type="text" name="eventfrom" class="dw_davcal__appearance dw_davcal__date" id="dw_davcal__appearance_from"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['to'] + '</td><td><input type="text" name="eventto" class="dw_davcal__appearance dw_davcal__date" id="dw_davcal__appearance_to"></td></tr>' +
            '<tr><td>Event Website</td><td><table id="dw_davcal__appearance_links"><tbody><tr><td><input type="text" id="dw_davcal__appearance_link_input" value="http://"></td><td><a href="#" id="dw_davcal__appearance_link_add">Add</a></td></tr></tbody></table>' +
            '<div id="dw_davcal__appearance_page_link" style="margin-top:4px;font-size:1.05em;"></div>' +
            '<div id="dw_davcal__appearance_page_regen" style="margin-top:4px;font-size:1.05em;display:none;"><a href="#" id="dw_davcal__regen_btn" style="color:#c0392b;">↻ Regenerate Event Page</a> <span id="dw_davcal__regen_result"></span></div>' +
            '<div id="dw_davcal__appearance_members_note" style="margin-top:4px;font-size:1.05em;display:none;"></div>' +
            '</td></tr>' +
            '<tr><td colspan="2"><hr></td></tr>' +
            submitterHtml +
            '<tr><td colspan="2">' +
            '<div id="dw_davcal__also_going_section" style="display:none;">' +
            '<button id="dw_davcal__also_going_toggle" style="margin-bottom:4px;">★ I\'m also going to this event!</button>' +
            '<div style="font-size:0.9em;font-style:italic;color:#666;margin-bottom:6px;">You only need to submit this once, but it\'s editable at any time on the event page.</div>' +
            '<div id="dw_davcal__also_going_fields" style="display:none;">' +
            '<table>' +
            '<tr><td>Your Name</td><td><input type="text" id="dw_davcal__also_name"></td></tr>' +
            '<tr><td>Table Number</td><td><input type="text" id="dw_davcal__also_table"></td></tr>' +
            '<tr><td colspan="2"><label><input type="checkbox" id="dw_davcal__also_panels_check"> Participating in panels/workshops?</label></td></tr>' +
            '<tr id="dw_davcal__also_panels_row" style="display:none"><td>Details</td><td><textarea id="dw_davcal__also_panels"></textarea></td></tr>' +
            '<tr><td colspan="2"><label><input type="checkbox" id="dw_davcal__also_stamprally"> Participate in stamp rally?</label></td></tr>' +
            '</table>' +
            '<button id="dw_davcal__also_going_btn" style="margin-top:6px;">Submit</button>' +
            '<span id="dw_davcal__also_going_result" style="margin-left:8px;"></span>' +
            '</div>' +
            '</div>' +
            '</td></tr>' +
            '</table>' +
            '<div id="dw_davcal__ajaxedit"></div>' +
            '</div>'
        )
        .parent()
        .attr('id','dw_davcal__edit')
        .show()
        .appendTo('.dokuwiki:first');

        // Re-point $editEventDialog to the wrapper so hideEditEventDialog removes everything
        dw_davcal__modals.$editEventDialog = jQuery('#dw_davcal__edit');

        jQuery('#dw_davcal__edit').position({ my: "center", at: "center", of: window });

        // Bind X button directly
        jQuery('#dw_davcal__edit').find('.ui-dialog-titlebar-close').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            dw_davcal__modals.hideEditEventDialog();
        });

        // Date pickers — force English to avoid locale bleed from FullCalendar
        jQuery('.dw_davcal__appearance.dw_davcal__date').datepicker(jQuery.extend({}, jQuery.datepicker.regional[''], { dateFormat: 'yy-mm-dd' }));

        // Set values
        jQuery('#dw_davcal__appearance_name').val(calEvent.title || '');
        jQuery('#dw_davcal__appearance_desc').val(calEvent.description || '');
        jQuery('#dw_davcal__appearance_location').val(calEvent.location || '');
        jQuery('#dw_davcal__appearance_from').val(calEvent.start ? calEvent.start.format('YYYY-MM-DD') : '');
        // FC3 all-day end is exclusive (day+1) — subtract one day for display
        var displayEnd = '';
        if(calEvent.end) {
            displayEnd = calEvent.allDay
                ? moment(calEvent.end).subtract(1, 'days').format('YYYY-MM-DD')
                : calEvent.end.format('YYYY-MM-DD');
        }
        jQuery('#dw_davcal__appearance_to').val(displayEnd);

        // Hidden fields needed by save
        jQuery('#dw_davcal__edit').append(
            '<input type="hidden" name="uid" class="dw_davcal__appearance" id="dw_davcal__appearance_uid">' +
            '<input type="hidden" name="detectedtz" class="dw_davcal__appearance" id="dw_davcal__appearance_tz">' +
            '<input type="hidden" name="currenttz" class="dw_davcal__appearance" id="dw_davcal__appearance_currenttz">' +
            '<input type="hidden" name="edit_mode" class="dw_davcal__appearance" id="dw_davcal__appearance_editmode">' +
            '<input type="hidden" name="occurrence_date" class="dw_davcal__appearance" id="dw_davcal__appearance_occdate">'
        );
        jQuery('#dw_davcal__appearance_uid').val(calEvent.id || '0');
        jQuery('#dw_davcal__appearance_tz').val(dw_davcal__modals.detectedTz);
        jQuery('#dw_davcal__appearance_currenttz').val(dw_davcal__modals.currentTz);
        jQuery('#dw_davcal__appearance_editmode').val(calEvent._recurringMode || 'all');
        jQuery('#dw_davcal__appearance_occdate').val(calEvent._occurrenceDate || '');

        // Existing links
        if(calEvent.attachments) {
            for(var i=0; i<calEvent.attachments.length; i++) {
                var link = calEvent.attachments[i];
                jQuery('#dw_davcal__appearance_links tbody').prepend(
                    '<tr><td><a href="' + link + '" class="dw_davcal__editevent_attachment_link">' + link + '</a></td>' +
                    '<td><a href="#" class="dw_davcal__appearance_link_delete">Delete</a></td></tr>'
                );
            }
        }

        // Add link button
        jQuery('#dw_davcal__appearance_link_add').on('click', function(e) {
            e.preventDefault();
            var val = jQuery('#dw_davcal__appearance_link_input').val();
            if(val && val !== 'http://') {
                jQuery('#dw_davcal__appearance_links tbody').prepend(
                    '<tr><td><a href="' + val + '" class="dw_davcal__editevent_attachment_link">' + val + '</a></td>' +
                    '<td><a href="#" class="dw_davcal__appearance_link_delete">Delete</a></td></tr>'
                );
                jQuery('#dw_davcal__appearance_link_input').val('http://');
            }
        });

        jQuery('#dw_davcal__edit').on('click', '.dw_davcal__appearance_link_delete', function(e) {
            e.preventDefault();
            jQuery(this).closest('tr').remove();
        });

        // Submitter attending toggle — only present for new events
        if(!edit) {
            jQuery('#dw_davcal__submitter_attending').on('change', function() {
                jQuery('#dw_davcal__submitter_fields').toggle(jQuery(this).prop('checked'));
            });
            jQuery('#dw_davcal__submitter_panels_check').on('change', function() {
                jQuery('#dw_davcal__submitter_panels_row').toggle(jQuery(this).prop('checked'));
            });
        }

        // Also going toggle button
        jQuery('#dw_davcal__also_going_toggle').on('click', function(e) {
            e.preventDefault();
            jQuery('#dw_davcal__also_going_fields').toggle();
        });

        jQuery('#dw_davcal__also_panels_check').on('change', function() {
            jQuery('#dw_davcal__also_panels_row').toggle(jQuery(this).prop('checked'));
        });

        // "I'm also going" — shown for existing events that have a page
        if(edit && calEvent.title) {
            jQuery.post(
                DOKU_BASE + 'lib/exe/ajax.php',
                {
                    call: 'plugin_davcal',
                    id: pageid,
                    page: dw_davcal__modals.page,
                    action: 'checkEventPage',
                    params: { title: calEvent.title, date: calEvent.start.format('YYYY-MM-DD') },
                    sectok: JSINFO.plugin.davcal['sectok']
                },
                function(data) {
                    if(data['result'] === true && data['exists'] === true) {
                        // Show link to event page after Event Website field
                        jQuery('#dw_davcal__appearance_page_link').html(
                            '<a href="' + data['url'] + '" target="_blank">View Event Page →</a>'
                        );
                        jQuery('#dw_davcal__appearance_page_regen').show();
                        jQuery('#dw_davcal__appearance_page_regen').data('page_id', data['page_id']);
                        jQuery('#dw_davcal__also_going_section').show();
                        jQuery('#dw_davcal__also_going_section').data('page_id', data['page_id']);
                        // Show members going text if applicable
                        if(calEvent.members && calEvent.members != 0) {
                            jQuery('#dw_davcal__appearance_members_note').html(
                                '<a href="' + data['url'] + '#members_at_this_event" target="_blank">★ Members are going to this event!</a>'
                            ).show();
                        }
                    }
                }
            );
        }

        // Regenerate event page
        jQuery('#dw_davcal__regen_btn').on('click', function(e) {
            e.preventDefault();
            var pageId = jQuery('#dw_davcal__appearance_page_regen').data('page_id');
            if(!confirm('Regenerate the event page? Notes and Recording Links sections will be preserved, but all other content will be updated from the calendar.'))
                return;
            jQuery('#dw_davcal__regen_result').text('Regenerating...');
            var links = [];
            jQuery('.dw_davcal__editevent_attachment_link').each(function() {
                var a = jQuery(this).attr('href');
                if(a) links.push(a);
            });
            jQuery.post(
                DOKU_BASE + 'lib/exe/ajax.php',
                {
                    call: 'plugin_davcal',
                    id: pageid,
                    page: dw_davcal__modals.page,
                    action: 'regenAppearancePage',
                    params: {
                        page_id: pageId,
                        uid: calEvent.id || '',
                        eventname: calEvent.title || '',
                        eventfrom: calEvent.start ? calEvent.start.format('YYYY-MM-DD') : '',
                        eventto: calEvent.end ? moment(calEvent.end).subtract(1, 'days').format('YYYY-MM-DD') : '',
                        eventdescription: jQuery('#dw_davcal__appearance_desc').val(),
                        eventlocation: jQuery('#dw_davcal__appearance_location').val(),
                        attachments: links,
                        members: calEvent.members || '0',
                        submitter_attending: '0'
                    },
                    sectok: JSINFO.plugin.davcal['sectok']
                },
                function(data) {
                    if(data && data['result'] === true) {
                        jQuery('#dw_davcal__regen_result').html('Done! <a href="' + data['url'] + '" target="_blank">View page</a>');
                    } else {
                        jQuery('#dw_davcal__regen_result').text('Error: ' + (data && data['html'] ? data['html'] : 'failed'));
                    }
                }
            );
        });

        jQuery('#dw_davcal__also_going_btn').on('click', function() {
            var pageId = jQuery('#dw_davcal__also_going_section').data('page_id');
            var panels = jQuery('#dw_davcal__also_panels_check').prop('checked') ? jQuery('#dw_davcal__also_panels').val() : '';
            if(!pageId) {
                jQuery('#dw_davcal__also_going_result').text('Error: no page ID found. Try closing and reopening the event.');
                return;
            }
            jQuery('#dw_davcal__also_going_result').text('Saving...');
            jQuery.post(
                DOKU_BASE + 'lib/exe/ajax.php',
                {
                    call: 'plugin_davcal',
                    id: pageid,
                    page: dw_davcal__modals.page,
                    action: 'appendMember',
                    params: {
                        page_id: pageId,
                        uid: calEvent.id || '',
                        submitter_name: jQuery('#dw_davcal__also_name').val(),
                        submitter_table: jQuery('#dw_davcal__also_table').val(),
                        submitter_panels: panels,
                        submitter_stamprally: jQuery('#dw_davcal__also_stamprally').prop('checked') ? 1 : 0
                    },
                    sectok: JSINFO.plugin.davcal['sectok']
                },
                function(data) {
                    if(data && data['result'] === true) {
                        jQuery('#dw_davcal__also_going_result').html('Added! <a href="' + data['url'] + '" target="_blank">View page</a>');
                        jQuery('#dw_davcal__appearance_members_note').html(
                            '<a href="' + data['url'] + '#members_at_this_event" target="_blank">★ Members are going to this event!</a>'
                        ).show();
                        jQuery('#fullCalendar').fullCalendar('refetchEvents');
                    } else {
                        var errMsg = (data && data['html']) ? data['html'] : 'Request failed';
                        jQuery('#dw_davcal__also_going_result').text('Error: ' + errMsg);
                    }
                }
            ).fail(function(jqXHR, textStatus, errorThrown) {
                console.log('[davcal] appendMember AJAX fail:', textStatus, errorThrown, jqXHR.responseText);
                jQuery('#dw_davcal__also_going_result').text('Error: ' + textStatus + ' - ' + jqXHR.responseText.substring(0, 200));
            });
        });

    },

    /**
     * Show the edit event dialog, which is also used to create new events
     * @param {Object} event The event to create, that is the date or the calEvent
     * @param {Object} edit  Whether we edit (true) or create a new event (false)
     */
    showEditEventDialog : function(event, edit) {
        if(dw_davcal__modals.$editEventDialog)
            return;
        
        var readonly = true;
        for(var i=0; i<dw_davcal__modals.settings['calids'].length; i++)
        {
            if(edit)
            {
                // Use the specific calendar setting if we edit an event
                if(event.page == dw_davcal__modals.settings['calids'][i]['page'])
                  readonly = !dw_davcal__modals.settings['calids'][i]['write'];
            }
            else
            {
                // If there is at least one writable calendar,
                // we set readonly to false
                if(dw_davcal__modals.settings['calids'][i]['write'])
                  readonly = false;
            }
        }

        var title = '';   
        var dialogButtons = {};
        var calEvent = [];
        var recurringWarning = '';
        // Buttons are dependent on edit or create
        // Several possibilities:
        //
        // 1) Somebody tries to edit, it is not recurring and not readonly -> show
        // 2) Somebody tries to edit, it is recurring and not readonly, no mode chosen yet -> show choice dialog
        // 3) Somebody tries to edit, it is recurring and not readonly, mode chosen -> show edit dialog
        // 4) Somebody tries to edit, it is readonly -> message
        // 5) Somebody tries to create and it is readonly -> message
        // 6) Somebody tries to create -> show
        if(edit && (event.recurring != true) && (readonly === false))
        {
            calEvent = event;
            title = LANG.plugins.davcal['edit_event'];
            dialogButtons[LANG.plugins.davcal['edit']] = function() {
                if(!dw_davcal__modals.checkEvents())
                  return;
                var postArray = { };
                var attachArr = new Array();
                var pageid = calEvent.page;

                jQuery('.dw_davcal__editevent_attachment_link').each(function() {
                    var attachment = jQuery(this).attr('href');
                    if(attachment != undefined)
                    {
                        attachArr.push(attachment);
                    }
                });
                postArray['attachments'] = attachArr;
                jQuery("input.dw_davcal__editevent, textarea.dw_davcal__editevent, select.dw_davcal__editevent").each(function() {
                  if(jQuery(this).attr('type') == 'checkbox')
                  {
                      postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
                  }
                  else
                  {
                      postArray[jQuery(this).prop('name')] = jQuery(this).val();
                  }
                });
                jQuery('#dw_davcal__ajaxedit').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_davcal',
                        id: pageid,
                        page: dw_davcal__modals.page,
                        action: 'editEvent',
                        params: postArray,
                        sectok: JSINFO.plugin.davcal['sectok']
                    },
                    function(data)
                    {
                        var result = data['result'];
                        var html = data['html'];
                        jQuery('#dw_davcal__ajaxedit').html(html);
                        if(result === true)
                        {
                            jQuery('#fullCalendar').fullCalendar('refetchEvents');
                            dw_davcal__modals.hideEditEventDialog();
                        }
                    }
                );
            };
            dialogButtons[LANG.plugins.davcal['delete']] = function() {
                dw_davcal__modals.action = 'deleteEvent';
                dw_davcal__modals.msg = LANG.plugins.davcal['really_delete_this_event'];
                dw_davcal__modals.completeCb = function(data) {
                    var result = data['result'];
                    var html = data['html'];
                    jQuery('#dw_davcal__ajaxedit').html(html);                    
                    if(result === true)
                    {
                        jQuery('#fullCalendar').fullCalendar('refetchEvents');
                        dw_davcal__modals.hideEditEventDialog();
                    }
                };
                dw_davcal__modals.showDialog(true);
            };
        }
        else if(edit && (event.recurring == true) && (readonly === false) && !event._recurringMode)
        {
            // No mode chosen yet — show the choice dialog and bail out
            dw_davcal__modals.showRecurringChoiceDialog(event);
            return;
        }
        else if(edit && (event.recurring == true) && (readonly === false) && event._recurringMode)
        {
            // Mode has been chosen — show the edit dialog
            calEvent = event;
            title = (event._recurringMode === 'single') ? 'Edit This Event' : 'Edit All Events';
            dialogButtons[LANG.plugins.davcal['edit']] = function() {
                if(!dw_davcal__modals.checkEvents())
                  return;
                var postArray = {};
                var attachArr = new Array();
                var pageid = calEvent.page;
                jQuery('.dw_davcal__editevent_attachment_link').each(function() {
                    var attachment = jQuery(this).attr('href');
                    if(attachment != undefined) attachArr.push(attachment);
                });
                postArray['attachments'] = attachArr;
                jQuery("input.dw_davcal__editevent, textarea.dw_davcal__editevent, select.dw_davcal__editevent").each(function() {
                    if(jQuery(this).attr('type') == 'checkbox')
                        postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
                    else
                        postArray[jQuery(this).prop('name')] = jQuery(this).val();
                });
                jQuery('#dw_davcal__ajaxedit').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_davcal',
                        id: pageid,
                        page: dw_davcal__modals.page,
                        action: 'editEvent',
                        params: postArray,
                        sectok: JSINFO.plugin.davcal['sectok']
                    },
                    function(data) {
                        var result = data['result'];
                        var html = data['html'];
                        jQuery('#dw_davcal__ajaxedit').html(html);
                        if(result === true) {
                            jQuery('#fullCalendar').fullCalendar('refetchEvents');
                            dw_davcal__modals.hideEditEventDialog();
                        }
                    }
                );
            };
            dialogButtons[LANG.plugins.davcal['delete']] = function() {
                dw_davcal__modals.action = 'deleteEvent';
                dw_davcal__modals.msg = (event._recurringMode === 'single') ?
                    LANG.plugins.davcal['really_delete_this_occurrence'] :
                    LANG.plugins.davcal['really_delete_all_occurrences'];
                dw_davcal__modals.completeCb = function(data) {
                    var result = data['result'];
                    var html = data['html'];
                    jQuery('#dw_davcal__ajaxedit').html(html);
                    if(result === true) {
                        jQuery('#fullCalendar').fullCalendar('refetchEvents');
                        dw_davcal__modals.hideEditEventDialog();
                    }
                };
                dw_davcal__modals.showDialog(true);
            };
        }
        else if(edit && (readonly === true))
        {
            calEvent = event;
            title = LANG.plugins.davcal['edit_event'];
            recurringWarning = LANG.plugins.davcal['no_permission'];
        }
        else if(readonly === true)
        {
            calEvent.start = event;
            calEvent.end = moment(event);
            calEvent.start.hour(12);
            calEvent.start.minute(0);
            calEvent.end.hour(13);
            calEvent.end.minute(0);
            calEvent.allDay = false;
            calEvent.recurring = false;
            calEvent.title = '';
            calEvent.description = '';
            calEvent.id = '0';
            calEvent.location = '';
            calEvent.page = dw_davcal__modals.page;
            title = LANG.plugins.davcal['create_new_event'];
            recurringWarning = LANG.plugins.davcal['no_permission'];
        }
        else
        {
            calEvent.start = event;
            calEvent.end = moment(event);
            calEvent.start.hour(12);
            calEvent.start.minute(0);
            calEvent.end.hour(13);
            calEvent.end.minute(0);
            calEvent.allDay = false;
            calEvent.recurring = false;
            calEvent.title = '';
            calEvent.description = '';
            calEvent.location = '';
            calEvent.id = '0';
            calEvent.page = dw_davcal__modals.settings['calids'][0]['page'];
            title = LANG.plugins.davcal['create_new_event'];
            dialogButtons[LANG.plugins.davcal['create']] = function() {
                if(!dw_davcal__modals.checkEvents())
                  return;

                var postArray = { };
                var attachArr = new Array();
                var pageid = jQuery("#dw_davcal__editevent_calendar option:selected").val();
                jQuery("input.dw_davcal__editevent, textarea.dw_davcal__editevent, select.dw_davcal__editevent").each(function() {
                  if(jQuery(this).attr('type') == 'checkbox')
                  {
                      postArray[jQuery(this).prop('name')] = jQuery(this).prop('checked') ? 1 : 0;
                  }
                  else
                  {
                      postArray[jQuery(this).prop('name')] = jQuery(this).val();
                  }
                });
                jQuery('.dw_davcal__editevent_attachment_link').each(function() {
                    var attachment = jQuery(this).attr('href');
                    if(attachment != undefined)
                    {
                        attachArr.push(attachment);
                    }
                });
                postArray['attachments'] = attachArr;
                jQuery('#dw_davcal__ajaxedit').html('<img src="'+DOKU_BASE+'lib/images/throbber.gif" alt="" width="16" height="16" />');
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_davcal',
                        id: pageid,
                        page: dw_davcal__modals.page,
                        action: 'newEvent',
                        params: postArray,
                        sectok: JSINFO.plugin.davcal['sectok']
                    },
                    function(data)
                    {
                        var result = data['result'];
                        var html = data['html'];
                        jQuery('#dw_davcal__ajaxedit').html(html);
                        if(result === true)
                        {
                            jQuery('#fullCalendar').fullCalendar('refetchEvents');
                            dw_davcal__modals.hideEditEventDialog();
                        }
                    }
                );
            };
        }
        dialogButtons[LANG.plugins.davcal['cancel']] = function() {
            dw_davcal__modals.hideEditEventDialog();
        };
        dw_davcal__modals.uid = calEvent.id;
        dw_davcal__modals.$editEventDialog = jQuery(document.createElement('div'))
       .dialog({
           autoOpen: false,
           draggable: true,
           title: title,
           resizable: true,         
           buttons: dialogButtons,
       })
       .html(
            '<div><table>' + 
            '<tr><td>' + LANG.plugins.davcal['calendar'] + '</td><td><select id="dw_davcal__editevent_calendar"></select></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['title'] + '</td><td><input type="text" id="dw_davcal__eventname_edit" name="eventname" class="dw_davcal__editevent"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['description'] + '</td><td><textarea name="eventdescription" id="dw_davcal__eventdescription_edit" class="dw_davcal__editevent dw_davcal__text"></textarea></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['location'] + '</td><td><input type="text" id="dw_davcal__eventlocation_edit" name="eventlocation" class="dw_davcal__editevent"></td></tr>' + 
            '<tr><td>' + LANG.plugins.davcal['from'] + '</td><td><input type="text" name="eventfrom" id="dw_davcal__eventfrom_edit" class="dw_davcal__editevent dw_davcal__date"><input type="text" name="eventfromtime" id="dw_davcal__eventfromtime_edit" class="dw_davcal__editevent dw_davcal__time"></td></tr>' +
            '<tr><td>' + LANG.plugins.davcal['to'] + '</td><td><input type="text" name="eventto" id="dw_davcal__eventto_edit" class="dw_davcal__editevent dw_davcal__date"><input type="text" name="eventtotime" id="dw_davcal__eventtotime_edit" class="dw_davcal__editevent dw_davcal__time"></td></tr>' +
            '<tr><td colspan="2">' + LANG.plugins.davcal['allday'] + '<input type="checkbox" name="allday" id="dw_davcal__allday_edit" class="dw_davcal__editevent" style="margin-left:6px;"></td></tr>' +
            '<tr><td colspan="2">Public Event<input type="checkbox" name="public" id="dw_davcal__public_edit" class="dw_davcal__editevent" style="margin-left:6px;" title="Show this event on the public calendar"></td></tr>' +
            '<tr><td>Repeats</td><td><select name="recurrence" id="dw_davcal__recurrence_edit" class="dw_davcal__editevent"><option value="">Does not repeat</option><option value="DAILY">Daily</option><option value="WEEKLY">Weekly</option><option value="BIWEEKLY">Every two weeks</option><option value="MONTHLY">Monthly</option><option value="YEARLY">Yearly</option></select></td></tr>' +
            '<tr id="dw_davcal__weekly_options" style="display:none"><td>On</td><td>' +
            '<select name="recurrence_weekday" id="dw_davcal__recurrence_weekday_edit" class="dw_davcal__editevent">' +
            '<option value="SU">Sunday</option><option value="MO">Monday</option><option value="TU">Tuesday</option><option value="WE">Wednesday</option><option value="TH">Thursday</option><option value="FR">Friday</option><option value="SA">Saturday</option>' +
            '</select></td></tr>' +
            '<tr id="dw_davcal__monthly_options" style="display:none"><td>Monthly on</td><td>' +
            '<select id="dw_davcal__monthly_mode_edit" name="recurrence_monthly_mode" class="dw_davcal__editevent">' +
            '<option value="weekday">Same weekday (e.g. third Tuesday)</option>' +
            '<option value="date">Same date (e.g. the 15th)</option>' +
            '</select>' +
            '</td></tr>' +
            '<tr id="dw_davcal__monthly_weekday_options" style="display:none"><td>On the</td><td>' +
            '<select name="recurrence_bysetpos" id="dw_davcal__recurrence_bysetpos_edit" class="dw_davcal__editevent">' +
            '<option value="1">First</option><option value="2">Second</option><option value="3">Third</option><option value="4">Fourth</option><option value="-1">Last</option>' +
            '</select> ' +
            '<select name="recurrence_byday" id="dw_davcal__recurrence_byday_edit" class="dw_davcal__editevent">' +
            '<option value="SU">Sunday</option><option value="MO">Monday</option><option value="TU">Tuesday</option><option value="WE">Wednesday</option><option value="TH">Thursday</option><option value="FR">Friday</option><option value="SA">Saturday</option>' +
            '</select></td></tr>' +
            '<tr><td>Links</td><td><table id="dw_davcal__editevent_attachments"><tbody><tr><td><input type="text" id="dw_davcal__editevent_attachment" value="http://"></td><td><a href="#" id="dw_davcal__editevent_attach">' + LANG.plugins.davcal['add_attachment'] + '</a></td></tr></tbody></table></td></tr>' +
            '<tr id="dw_davcal__eventpage_row" style="display:none"><td>Event Page</td><td><span id="dw_davcal__eventpage_btn"></span></td></tr>' +
            '<tr id="dw_davcal__addtocal_row" style="display:none"><td>Add to Calendar</td><td><span id="dw_davcal__addtocal_links"></span></td></tr>' +
            '</table>' +
            recurringWarning + 
            '<input type="hidden" name="uid" id="dw_davcal__uid_edit" class="dw_davcal__editevent">' +
            '<input type="hidden" name="edit_mode" id="dw_davcal__edit_mode" class="dw_davcal__editevent">' +
            '<input type="hidden" name="occurrence_date" id="dw_davcal__occurrence_date" class="dw_davcal__editevent">' +
            '<input type="hidden" name="detectedtz" id="dw_davcal__tz_edit" class="dw_davcal__editevent">' +
            '<input type="hidden" name="currenttz" id="dw_davcal__currenttz_edit" class="dw_davcal__editevent">' +
            '</div>' +
            '<div id="dw_davcal__ajaxedit"></div>'
            )
       .parent()
       .attr('id','dw_davcal__edit')
       .show()
       .appendTo('.dokuwiki:first');
       
       jQuery('#dw_davcal__edit').position({
           my: "center",
           at: "center",
           of: window
       });
       
       // Populate calendar dropdown
       var $dropdown = jQuery("#dw_davcal__editevent_calendar");
       for(var i=0; i<dw_davcal__modals.settings['calids'].length; i++)
       {
           var sel = '';
           // When creating an event, do not show read-only calendars
           if(!edit && (dw_davcal__modals.settings['calids'][i]['write'] === false))
             continue;
           // Never show appearances calendar in the standard dialog
           if(dw_davcal__modals.settings['calids'][i]['page'] === 'calendars:appearances_calendar')
             continue;
           if(calEvent.page == dw_davcal__modals.settings['calids'][i]['page'])
             sel = ' selected="selected"';
           $dropdown.append('<option value="' + dw_davcal__modals.settings['calids'][i]['page'] + '"' + sel + '>' + dw_davcal__modals.settings['calids'][i]['name'] + '</option>');
       }
       if(edit || (dw_davcal__modals.settings['calids'].length < 1))
       {
           $dropdown.prop('disabled', true);
       }
       
       // Set up existing/predefined values
       jQuery('#dw_davcal__tz_edit').val(dw_davcal__modals.detectedTz);
       jQuery('#dw_davcal__currenttz_edit').val(dw_davcal__modals.currentTz);
       jQuery('#dw_davcal__uid_edit').val(calEvent.id);
       jQuery('#dw_davcal__edit_mode').val(calEvent._recurringMode || 'all');
       jQuery('#dw_davcal__occurrence_date').val(calEvent._occurrenceDate || '');
       jQuery('#dw_davcal__eventname_edit').val(calEvent.title);
       jQuery('#dw_davcal__eventlocation_edit').val(calEvent.location);
       jQuery('#dw_davcal__eventfrom_edit').val(calEvent.start.format('YYYY-MM-DD'));
       jQuery('#dw_davcal__eventfromtime_edit').val(calEvent.start.format('HH:mm'));
       jQuery('#dw_davcal__eventdescription_edit').val(calEvent.description);
       // Auto-resize description to fit content on open
       var $desc = jQuery('#dw_davcal__eventdescription_edit');
       $desc.css('height', 'auto').css('height', $desc[0].scrollHeight + 'px');
       if(calEvent.attachments && (calEvent.attachments !== null))
       {
           for(var i=0; i<calEvent.attachments.length; i++)
           {
               var url = calEvent.attachments[i];
               var row = '<tr><td><a href="' + url + '" class="dw_davcal__editevent_attachment_link" target="_blank">' + url + '</a></td><td><a class="editLink" href="#" style="margin-right:6px;">' + 'Edit' + '</a><a class="deleteLink" href="#">' + LANG.plugins.davcal['delete'] + '</a></td></tr>';
               jQuery('#dw_davcal__editevent_attachments > tbody:last').append(row);
           }
       }
       dw_davcal__modals.attachAttachmentDeleteHandlers();
       jQuery('#dw_davcal__editevent_attach').on("click", function(e)
       {
           e.preventDefault();
           var url = jQuery('#dw_davcal__editevent_attachment').val();
           if(url == '')
             return false;
           jQuery('#dw_davcal__editevent_attachment').val('http://');
           var row = '<tr><td><a href="' + url + '" class="dw_davcal__editevent_attachment_link" target="_blank">' + url + '</a></td><td><a class="editLink" href="#" style="margin-right:6px;">Edit</a><a class="deleteLink" href="#">' + LANG.plugins.davcal['delete'] + '</a></td></tr>';
           jQuery('#dw_davcal__editevent_attachments > tbody:last').append(row);
           dw_davcal__modals.attachAttachmentDeleteHandlers();
           return false;
       });
       if(calEvent.allDay && (calEvent.end === null))
       {
           jQuery('#dw_davcal__eventto_edit').val(calEvent.start.format('YYYY-MM-DD'));
           jQuery('#dw_davcal__eventtotime_edit').val(calEvent.start.format('HH:mm'));
       }
       else if(calEvent.allDay)
       {
           endEvent = moment(calEvent.end);
           endEvent.subtract(1, 'days');
           jQuery('#dw_davcal__eventto_edit').val(endEvent.format('YYYY-MM-DD'));
           jQuery('#dw_davcal__eventotime_edit').val(endEvent.format('HH:mm'));
       }
       else
       {
           jQuery('#dw_davcal__eventto_edit').val(calEvent.end.format('YYYY-MM-DD'));
           jQuery('#dw_davcal__eventtotime_edit').val(calEvent.end.format('HH:mm'));
       }
       jQuery('#dw_davcal__allday_edit').prop('checked', calEvent.allDay);
       jQuery('#dw_davcal__public_edit').prop('checked', calEvent.public === '1');

       // Set recurrence value and show/hide weekly/monthly options
       var recurrenceVal = calEvent.recurrence ? calEvent.recurrence : '';
       jQuery('#dw_davcal__recurrence_edit').val(recurrenceVal);

       if(recurrenceVal === 'WEEKLY' || recurrenceVal === 'BIWEEKLY') {
           jQuery('#dw_davcal__weekly_options').show();
           jQuery('#dw_davcal__monthly_options').hide();
           if(calEvent.recurrence_weekday) {
               jQuery('#dw_davcal__recurrence_weekday_edit').val(calEvent.recurrence_weekday);
           } else {
               var dayNames = ['SU','MO','TU','WE','TH','FR','SA'];
               jQuery('#dw_davcal__recurrence_weekday_edit').val(dayNames[calEvent.start.day()]);
           }
       } else if(recurrenceVal === 'MONTHLY') {
           jQuery('#dw_davcal__monthly_options').show();
           if(calEvent.recurrence_byday) {
               jQuery('#dw_davcal__monthly_mode_edit').val('weekday');
               jQuery('#dw_davcal__monthly_weekday_options').show();
               jQuery('#dw_davcal__recurrence_byday_edit').val(calEvent.recurrence_byday);
               jQuery('#dw_davcal__recurrence_bysetpos_edit').val(calEvent.recurrence_bysetpos);
           } else {
               jQuery('#dw_davcal__monthly_mode_edit').val('date');
               jQuery('#dw_davcal__monthly_weekday_options').hide();
           }
       } else {
           jQuery('#dw_davcal__weekly_options').hide();
           jQuery('#dw_davcal__monthly_options').hide();
       }

       // Auto-resize description textarea as user types
       jQuery('#dw_davcal__eventdescription_edit').on('input', function() {
           jQuery(this).css('height', 'auto').css('height', this.scrollHeight + 'px');
       });

       // Location: show clickable open-link icon when value looks like a URL
       function updateLocationLink() {
           var val = jQuery('#dw_davcal__eventlocation_edit').val();
           jQuery('#dw_davcal__location_openlink').remove();
           if(val.match(/^https?:\/\/.+/)) {
               jQuery('#dw_davcal__eventlocation_edit').after(
                   '<a id="dw_davcal__location_openlink" href="' + val + '" target="_blank" style="margin-left:6px;">[🔗 Open Link]</a>'
               );
           }
       }
       updateLocationLink();
       jQuery('#dw_davcal__eventlocation_edit').on('input', updateLocationLink);
       jQuery('#dw_davcal__recurrence_edit').on('change', function() {
           var val = jQuery(this).val();
           if(val === 'WEEKLY' || val === 'BIWEEKLY') {
               jQuery('#dw_davcal__weekly_options').show();
               jQuery('#dw_davcal__monthly_options').hide();
               var dayNames = ['SU','MO','TU','WE','TH','FR','SA'];
               var startMoment = moment(jQuery('#dw_davcal__eventfrom_edit').val(), 'YYYY-MM-DD');
               jQuery('#dw_davcal__recurrence_weekday_edit').val(dayNames[startMoment.day()]);
           } else if(val === 'MONTHLY') {
               jQuery('#dw_davcal__monthly_options').show();
               jQuery('#dw_davcal__monthly_mode_edit').val('weekday');
               jQuery('#dw_davcal__monthly_weekday_options').show();
               var dayNames = ['SU','MO','TU','WE','TH','FR','SA'];
               var startMoment = moment(jQuery('#dw_davcal__eventfrom_edit').val(), 'YYYY-MM-DD');
               var startDay = dayNames[startMoment.day()];
               var weekOfMonth = Math.ceil(startMoment.date() / 7);
               if(weekOfMonth > 4) weekOfMonth = -1;
               jQuery('#dw_davcal__recurrence_byday_edit').val(startDay);
               jQuery('#dw_davcal__recurrence_bysetpos_edit').val(weekOfMonth);
           } else {
               jQuery('#dw_davcal__weekly_options').hide();
               jQuery('#dw_davcal__monthly_options').hide();
           }
       });

       // Monthly mode toggle
       jQuery(document).on('change', '#dw_davcal__monthly_mode_edit', function() {
           if(jQuery(this).val() === 'weekday') {
               jQuery('#dw_davcal__monthly_weekday_options').show();
           } else {
               jQuery('#dw_davcal__monthly_weekday_options').hide();
           }
       });

       // If editing an existing event with write access, check for an event page
       if(edit && !readonly && calEvent.title) {
           dw_davcal__modals.checkAndRenderEventPageButton(calEvent);
       }

       // Always render Add to Calendar links for existing events
       if(edit && calEvent.title) {
           jQuery('#dw_davcal__addtocal_row').show();
           dw_davcal__modals.renderAddToCalendarLinks(calEvent);
       }
       
        // attach event handlers
        jQuery('#dw_davcal__edit .ui-dialog-titlebar-close').click(function(){
          dw_davcal__modals.hideEditEventDialog();
        });
        jQuery('#dw_davcal__eventfrom_edit').datetimepicker({format:'YYYY-MM-DD',
                                                      formatDate:'YYYY-MM-DD',
                                                      datepicker: true,
                                                      timepicker: false,
                                                      lang: JSINFO.plugin.davcal['language'],
                                                      });
        jQuery('#dw_davcal__eventfromtime_edit').datetimepicker({format:'HH:mm',
                                                      formatTime:'HH:mm',
                                                      datepicker: false,
                                                      timepicker: true,
                                                      lang: JSINFO.plugin.davcal['language'],
                                                      step: 15});
        jQuery('#dw_davcal__eventto_edit').datetimepicker({format:'YYYY-MM-DD',
                                                      formatDate:'YYYY-MM-DD',
                                                      datepicker: true,
                                                      timepicker: false,
                                                      lang: JSINFO.plugin.davcal['language'],
                                                      });
        jQuery('#dw_davcal__eventtotime_edit').datetimepicker({format:'HH:mm',
                                                      formatTime:'HH:mm',
                                                      datepicker: false,
                                                      timepicker: true,
                                                      lang: JSINFO.plugin.davcal['language'],
                                                      step:15});
        jQuery('#dw_davcal__allday_edit').change(function() {
            if(jQuery(this).is(":checked"))
            {
                jQuery('#dw_davcal__eventfromtime_edit').prop('readonly', true);
                jQuery('#dw_davcal__eventtotime_edit').prop('readonly', true);
            }
            else
            {
                jQuery('#dw_davcal__eventfromtime_edit').prop('readonly', false);
                jQuery('#dw_davcal__eventtotime_edit').prop('readonly', false);
            }
        });
        jQuery('#dw_davcal__allday_edit').change();
    },
    
    /**
     * Attach handles to delete the attachments to all 'delete' links
     */
    attachAttachmentDeleteHandlers: function()
    {
       jQuery("#dw_davcal__editevent_attachments .deleteLink").on("click", function(e)
       {
            e.preventDefault();
            var tr = jQuery(this).closest('tr');
            tr.css("background-color", "#FF3700");
            tr.fadeOut(400, function()
            {
                tr.remove();
            });
            return false;
       });
       jQuery("#dw_davcal__editevent_attachments .editLink").on("click", function(e)
       {
           e.preventDefault();
           var $row = jQuery(this).closest('tr');
           var currentUrl = $row.find('.dw_davcal__editevent_attachment_link').attr('href');
           jQuery('#dw_davcal__editevent_attachment').val(currentUrl);
           $row.remove();
       });
    },

    /**
     * Show an info/confirmation dialog
     * @param {Object} confirm Whether a confirmation dialog (true) or an info dialog (false) is requested
     */
    showDialog : function(confirm)
    {
        if(dw_davcal__modals.$confirmDialog)
            return;
        var dialogButtons = {};
        var title = '';
        if(confirm)
        {
            title = LANG.plugins.davcal['confirmation'];
            var pageid = jQuery("#dw_davcal__editevent_calendar option:selected").val();
            // Appearances dialog doesn't have the calendar dropdown — use stored value
            if(!pageid && dw_davcal__modals._deleteExtra && dw_davcal__modals._deleteExtra.pageid)
                pageid = dw_davcal__modals._deleteExtra.pageid;
            dialogButtons[LANG.plugins.davcal['yes']] =  function() {
                            var deleteParams = { uid: dw_davcal__modals.uid };
                            // Carry the recurring choice through, otherwise deleting one
                            // occurrence takes the whole series with it.
                            var editMode = jQuery('#dw_davcal__edit_mode').val() ||
                                           jQuery('#dw_davcal__appearance_editmode').val() || '';
                            var occDate = jQuery('#dw_davcal__occurrence_date').val() ||
                                          jQuery('#dw_davcal__appearance_occdate').val() || '';
                            if(editMode === 'single' && occDate)
                            {
                                deleteParams['edit_mode'] = editMode;
                                deleteParams['occurrence_date'] = occDate;
                            }
                            // Merge any extra params needed for page deletion etc.
                            if(dw_davcal__modals._deleteExtra)
                                jQuery.extend(deleteParams, dw_davcal__modals._deleteExtra);
                            jQuery.post(
                                DOKU_BASE + 'lib/exe/ajax.php',
                                {
                                    call: 'plugin_davcal',
                                    id: pageid,
                                    page: dw_davcal__modals.page,
                                    action: dw_davcal__modals.action,
                                    params: deleteParams,
                                    sectok: JSINFO.plugin.davcal['sectok']
                                },
                                function(data)
                                {
                                    dw_davcal__modals._deleteExtra = null;
                                    dw_davcal__modals.completeCb(data);
                                }
                            );
                            dw_davcal__modals.hideDialog();
                    };
            dialogButtons[LANG.plugins.davcal['cancel']] = function() {
                            dw_davcal__modals.hideDialog();
                    };
        }
        else
        {
            title = LANG.plugins.davcal['info'];
            dialogButtons[LANG.plugins.davcal['ok']] = function() {
                 dw_davcal__modals.hideDialog();
            };
        }
        dw_davcal__modals.$dialog = jQuery(document.createElement('div'))
            .dialog({
                autoOpen: false,
                draggable: true,
                title: title,
                resizable: true,
                buttons: dialogButtons,
            })
            .html(
                '<div>' + dw_davcal__modals.msg + '</div>'
            )
            .parent()
            .attr('id','dw_davcal__confirm')
            .show()
            .appendTo('.dokuwiki:first');
   
            jQuery('#dw_davcal__confirm').position({
                my: "center",
                at: "center",
                of: window
            });
                 // attach event handlers
            jQuery('#dw_davcal__confirm .ui-dialog-titlebar-close').click(function(){
                dw_davcal__modals.hideDialog();
            });
    },
    
    /**
     * Hide the edit event dialog
     */
    hideEditEventDialog : function() {
        var $el = dw_davcal__modals.$editEventDialog;
        dw_davcal__modals.$editEventDialog = null;
        dw_davcal__modals.destroyDialog($el, '#dw_davcal__edit');
    },

    /**
     * Tear a dialog down completely.
     *
     * jQuery UI wraps the content div in a separate .ui-dialog element. Removing
     * only the content div leaves that wrapper in the DOM, where all.css gives it
     * position:absolute and z-index:20 over the calendar, so it swallows clicks.
     * Its id is reused by the next dialog, which then produces a duplicate id and
     * breaks every '#dw_davcal__edit ...' selector that follows.
     *
     * @param {Object} $el       The element the dialog was created on, may be null
     * @param {string} wrapperId Selector for the wrapper, cleaned up defensively
     */
    destroyDialog : function($el, wrapperId) {
        if($el && $el.length)
        {
            var $wrapper = $el.closest('.ui-dialog');
            if($el.hasClass('ui-dialog-content'))
            {
                try { $el.dialog('destroy'); } catch(e) { }
            }
            $el.empty();
            $el.remove();
            if($wrapper && $wrapper.length)
                $wrapper.remove();
        }
        // Sweep up any wrapper left behind by an earlier close.
        if(wrapperId)
            jQuery(wrapperId).remove();
    },
    
    /**
     * Hide the confirm/info dialog
     */
    hideDialog: function() {
        var $el = dw_davcal__modals.$dialog;
        dw_davcal__modals.$dialog = null;
        dw_davcal__modals.destroyDialog($el, '#dw_davcal__confirm');
    },
    
    /**
     * Hide the settings dialog
     */
    hideSettingsDialog: function() {
        var $el = dw_davcal__modals.$settingsDialog;
        dw_davcal__modals.$settingsDialog = null;
        dw_davcal__modals.destroyDialog($el, '#dw_davcal__settings');
    }
};
