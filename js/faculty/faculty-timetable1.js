$(document).ready(function () {
    $('#calendar').fullCalendar({
        header: {
            left: 'prev,next today',
            center: 'title',
            right: 'month,basicWeek,basicDay'
        },
        defaultDate: moment().format('YYYY-MM-DD'),
        navLinks: true,
        editable: true,
        eventLimit: true,
        events: calendarEvents
    });
});
