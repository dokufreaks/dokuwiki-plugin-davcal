ALTER TABLE calendars ADD disabled integer;
UPDATE calendars SET disabled = 0;
