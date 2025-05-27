# WooCommerce Store Manager

Un gestionale completo per WooCommerce che si connette tramite API REST per gestire prodotti, categorie, inventario e molto altro.

## Caratteristiche

- 📊 Dashboard con statistiche in tempo reale
- 🛍️ Gestione completa prodotti (semplici e variabili)
- 📁 Organizzazione categorie e tag
- 📦 Controllo inventario e stock
- 🔌 Connessione sicura tramite API WooCommerce
- 📱 Design responsive e moderno
- 🚨 Gestione errori e notifiche

## Requisiti Sistema

- PHP 7.4 o superiore
- Estensioni PHP: JSON, cURL, OpenSSL
- WooCommerce 3.5 o superiore
- HTTPS raccomandato per la produzione

## Installazione

1. **Download e Setup**
   ```bash
   git clone [repository-url]
   cd woocommerce-store-manager
   ```

2. **Configurazione Permessi**
   ```bash
   chmod 755 .
   chmod 644 *.php
   chmod 666 config.json (se esiste)
   ```

3. **Installazione Iniziale**
   - Visita `install.php` nel browser
   - Verifica i requisiti di sistema
   - Crea il file di configurazione

4. **Configurazione API WooCommerce**
   - Vai su `settings.php`
   - Inserisci URL del negozio e credenziali API
   - Testa la connessione

## Configurazione WooCommerce API

### Creazione API Keys

1. Accedi al pannello WordPress
2. Vai su **WooCommerce → Settings → Advanced → REST API**
3. Clicca **"Add key"**
4. Compila i campi:
   - **Description**: Store Manager
   - **User**: Amministratore
   - **Permissions**: Read/Write
5. Clicca **"Generate API Key"**
6. Copia Consumer Key e Consumer Secret

### Struttura File

```
/
├── config.php              # Gestione configurazione
├── WooCommerceAPI.php      # Classe API WooCommerce
├── dashboard.php           # Dashboard principale
├── settings.php            # Configurazione API
├── install.php             # Setup iniziale
├── check_connection.php    # Verifica connessione
├── config.json            # File configurazione (auto-generato)
├── .htaccess              # Configurazione Apache
└── README.md              # Documentazione
```

## Uso

### Dashboard
La dashboard mostra:
- Statistiche generali (prodotti, categorie, ordini)
- Grafici vendite
- Prodotti con stock basso
- Prodotti aggiunti di recente

### Gestione Errori
Il sistema gestisce automaticamente:
- Errori di connessione API
- Credenziali non valide
- Timeout delle richieste
- Risposte malformate

### Sicurezza
- Protezione file di configurazione
- Headers di sicurezza
- Validazione input
- Gestione errori sicura

## API Endpoints Utilizzati

| Endpoint | Uso | Metodo |
|----------|-----|--------|
| `/products` | Gestione prodotti | GET, POST, PUT, DELETE |
| `/products/categories` | Categorie | GET, POST, PUT, DELETE |
| `/orders` | Ordini | GET |
| `/reports/sales` | Report vendite | GET |

## Personalizzazione

### Aggiungere Nuove Funzionalità
1. Estendi la classe `WooCommerceAPI`
2. Crea nuove pagine seguendo la struttura esistente
3. Mantieni lo stile CSS coerente

### Modificare l'Interfaccia
- Il design usa TailwindCSS
- Mantieni la struttura HTML esistente
- Personalizza i colori nelle variabili CSS

## Troubleshooting

### Errori Comuni

**"Connection failed"**
- Verifica URL del negozio
- Controlla credenziali API
- Verifica che WooCommerce sia attivo

**"API not configured"**
- Vai su Settings e inserisci le credenziali
- Testa la connessione

**"Permission denied"**
- Verifica permessi file
- Controlla che la directory sia scrivibile

### Log degli Errori
Gli errori vengono loggati automaticamente. Controlla:
- Log del server web
- Log di PHP
- Console del browser per errori JavaScript

## Sviluppo

### Struttura del Codice
- **Separazione delle responsabilità**: Config, API, Presentazione
- **Gestione errori robusta**
- **Codice documentato e commentato**
- **Standard PSR per PHP**

### Testing
Prima del deployment:
1. Testa su ambiente di sviluppo
2. Verifica tutte le funzionalità API
3. Controlla responsive design
4. Valida sicurezza

## Licenza

MIT License - Vedi file LICENSE per dettagli

## Supporto

Per supporto tecnico:
1. Controlla la documentazione
2. Verifica i log degli errori
3. Testa la connessione API
4. Contatta il supporto se necessario

## Contribuire

1. Fork del repository
2. Crea branch feature
3. Commit delle modifiche
4. Push e pull request

---

**Nota**: Mantieni sempre le credenziali API sicure e non condividerle mai pubblicamente.