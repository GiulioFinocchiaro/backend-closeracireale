# Graphic Contest API Documentation

## Panoramica
API per gestire il contest grafico delle scuole. Include funzionalità per caricare grafiche, gestire like, approvazioni e visualizzazioni.

## Endpoint disponibili

### 1. Aggiungere una grafica (senza autenticazione)
**POST** `/api/graphic-contest/add`

**Tipo richiesta:** `multipart/form-data`

**Parametri:**
- `file` (file, obbligatorio): File della grafica (formati: jpg, jpeg, png, gif, pdf, ai, psd)
- `school_id` (int, obbligatorio): ID della scuola
- `name` (string, obbligatorio): Nome della grafica
- `description` (string, opzionale): Descrizione della grafica
- `uploader_name` (string, obbligatorio): Nome di chi carica la grafica
- `phone_number` (string, opzionale): Numero di telefono
- `class` (string, opzionale): Classe dello studente

**Risposta:**
```json
{
  "success": true,
  "message": "Grafica caricata con successo.",
  "graphic_id": 123,
  "file_path": "contest_abc123.jpg"
}
```

---

### 2. Aggiungere un like (senza autenticazione)
**POST** `/api/graphic-contest/like`

**Parametri JSON:**
```json
{
  "graphic_id": 123
}
```

**Risposta:**
```json
{
  "success": true,
  "message": "Like aggiunto con successo.",
  "total_likes": 15
}
```

---

### 3. Approvare o disapprovare una grafica (con autenticazione)
**PUT** `/api/graphic-contest/approve`

**Headers:** `Authorization: Bearer <token>`

**Parametri JSON:**
```json
{
  "graphic_id": 123,
  "status": 1
}
```

**Status values:**
- `0`: Disapprovata/In attesa
- `1`: Approvata

**Permessi richiesti:** `graphics.approve`

**Risposta:**
```json
{
  "success": true,
  "message": "Grafica approvata con successo.",
  "graphic_id": 123,
  "new_status": 1
}
```

---

### 4. Visualizzare tutte le grafiche approvate (senza autenticazione)
**GET** `/api/graphic-contest/approved`

**Risposta:**
```json
{
  "success": true,
  "graphics": [
    {
      "id": 123,
      "school_id": 1,
      "name": "Logo scuola",
      "description": "Nuovo logo per la scuola",
      "uploader_name": "Mario Rossi",
      "phone_number": "3331234567",
      "class": "5A",
      "file_path": "contest_abc123.jpg",
      "status": 1,
      "likes": 15,
      "created_at": "2024-01-15 10:30:00",
      "school_name": "Liceo Scientifico"
    }
  ],
  "count": 1
}
```

---

### 5. Visualizzare tutte le grafiche (con autenticazione)
**POST** `/api/graphic-contest/all`

**Headers:** `Authorization: Bearer <token>`

**Permessi richiesti:** `graphics.view_all`

**Risposta:** Stesso formato dell'endpoint approvate, ma include tutte le grafiche (anche quelle non approvate)

---

### 6. Modificare una grafica (con autenticazione)
**PUT** `/api/graphic-contest/update`

**Headers:** `Authorization: Bearer <token>`

**Parametri JSON:**
```json
{
  "graphic_id": 123,
  "name": "Nuovo nome",
  "description": "Nuova descrizione",
  "uploader_name": "Nome Aggiornato",
  "phone_number": "3339876543",
  "class": "5B"
}
```

**Permessi richiesti:** `graphics.update`

**Risposta:**
```json
{
  "success": true,
  "message": "Grafica aggiornata con successo.",
  "graphic_id": 123
}
```

---

### 7. Ottenere una singola grafica
**POST** `/api/graphic-contest/single`

**Parametri JSON:**
```json
{
  "graphic_id": 123
}
```

**Risposta:**
```json
{
  "success": true,
  "graphic": {
    "id": 123,
    "school_id": 1,
    "name": "Logo scuola",
    "description": "Nuovo logo per la scuola",
    "uploader_name": "Mario Rossi",
    "phone_number": "3331234567",
    "class": "5A",
    "file_path": "contest_abc123.jpg",
    "status": 1,
    "likes": 15,
    "created_at": "2024-01-15 10:30:00",
    "school_name": "Liceo Scientifico"
  }
}
```

## Permessi necessari

Per utilizzare le funzionalità con autenticazione, è necessario che l'utente abbia i seguenti permessi:

- `graphics.approve`: Per approvare/disapprovare grafiche
- `graphics.view_all`: Per visualizzare tutte le grafiche
- `graphics.update`: Per modificare grafiche

## Note tecniche

1. **File Upload**: I file vengono salvati nella directory specificata in `UPLOAD_DIR` nel file `.env`
2. **Sicurezza**: Le funzioni con autenticazione verificano il JWT token e i permessi dell'utente
3. **Database**: La tabella `graphic_contest` deve essere creata utilizzando lo script SQL fornito
4. **Formati supportati**: jpg, jpeg, png, gif, pdf, ai, psd
5. **Status**: 0 = disapprovata/in attesa, 1 = approvata

## Esempio di utilizzo

### Upload di una grafica (JavaScript)
```javascript
const formData = new FormData();
formData.append('file', file);
formData.append('school_id', 1);
formData.append('name', 'La mia grafica');
formData.append('description', 'Descrizione della grafica');
formData.append('uploader_name', 'Mario Rossi');
formData.append('phone_number', '3331234567');
formData.append('class', '5A');

fetch('/api/graphic-contest/add', {
  method: 'POST',
  body: formData
});
```

### Aggiunta di un like (JavaScript)
```javascript
fetch('/api/graphic-contest/like', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    graphic_id: 123
  })
});
```