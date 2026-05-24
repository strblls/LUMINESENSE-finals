const express = require('express');
const cors = require('cors');
const app = express();

app.use(express.json());

//CORS — allow local development origins used by the page
app.use(cors({
    origin(origin, callback) {
        if (!origin) {
            return callback(null, true);
        }

        let parsedOrigin;

        try {
            parsedOrigin = new URL(origin);
        } catch (error) {
            return callback(null, false);
        }

        const hostname = parsedOrigin.hostname;

        const isLocalHost =
            hostname === 'localhost' ||
            hostname === '127.0.0.1' ||
            hostname === '::1';

        const isPrivateNetwork =
            /^10\./.test(hostname) ||
            /^192\.168\./.test(hostname) ||
            /^172\.(1[6-9]|2\d|3[0-1])\./.test(hostname);

        const isLocalDevHostname = /\.local$/i.test(hostname);

        if (isLocalHost || isPrivateNetwork || isLocalDevHostname) {
            return callback(null, true);
        }

        return callback(null, false);
    },
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type']
}));

app.use('/gesture', require('./routes/gesture'));
app.use('/lighting', require('./routes/lighting')); 

//Catch-all — prevent Node from serving HTML files
app.use((req, res) => {
    res.status(404).json({ error: 'Route not foundee' });
});

app.listen(3000, () => console.log('Node server running on port 3000'));