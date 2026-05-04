const express = require('express');
const cors    = require('cors');
const app     = express();

app.use(express.json());

// CORS — explicitly allow Live Server
app.use(cors({
    origin: 'http://127.0.0.1:5500',
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type']
}));

app.use('/gesture', require('./routes/gesture'));

// Catch-all — prevent Node from serving HTML files
app.use((req, res) => {
    res.status(404).json({ error: 'Route not found' });
});

app.listen(3000, () => console.log('Node server running on port 3000'));