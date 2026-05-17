const express = require('express');
const cors    = require('cors');
const app     = express();

app.use(express.json());

//CORS — allow local development origins used by the page
app.use(cors({
    origin(origin, callback) {
        if (!origin) {
            return callback(null, true);
        }

        if (/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(origin)) {
            return callback(null, true);
        }

        return callback(null, false);
    },
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type']
}));

app.use('/gesture', require('./routes/gesture'));

//Catch-all — prevent Node from serving HTML files
app.use((req, res) => {
    res.status(404).json({ error: 'Route not foundee' });
});

app.listen(3000, () => console.log('Node server running on port 3000'));