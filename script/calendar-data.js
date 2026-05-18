// Calendar event data
const calendarEvents = [
    {
        title: 'All Day Event',
        start: moment().format('YYYY-MM-01')
    },
    {
        title: 'Long Event',
        start: moment().format('YYYY-MM-07'),
        end: moment().format('YYYY-MM-10')
    },
    {
        id: 999,
        title: 'Repeating Event',
        start: moment().hour(16).minute(0).second(0).format()
    },
    {
        id: 999,
        title: 'Repeating Event',
        start: moment().add(7, 'days').hour(16).minute(0).second(0).format()
    },
    {
        title: 'Conference',
        start: moment().format('YYYY-MM-11'),
        end: moment().format('YYYY-MM-13')
    },
    {
        title: 'Meeting',
        start: moment().format('YYYY-MM-12T10:30:00'),
        end: moment().format('YYYY-MM-12T12:30:00')
    },
    {
        title: 'Lunch',
        start: moment().format('YYYY-MM-12T12:00:00')
    },
    {
        title: 'Meeting',
        start: moment().format('YYYY-MM-12T14:30:00')
    },
    {
        title: 'Happy Hour',
        start: moment().format('YYYY-MM-12T17:30:00')
    },
    {
        title: 'Dinner',
        start: moment().format('YYYY-MM-12T20:00:00')
    },
    {
        title: 'Birthday Party',
        start: moment().format('YYYY-MM-13T07:00:00')
    }
];
