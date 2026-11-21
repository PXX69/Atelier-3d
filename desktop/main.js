// Liagos 3D : fenêtre native Windows qui affiche l'application embarquée.
const { app, BrowserWindow, Menu, shell } = require('electron');
const path = require('path');
const fs = require('fs');

// Les versions d'avant s'appelaient « Atelier 3D » : si leurs données existent, on les garde, pour qu'aucun projet enregistré ne disparaisse.
try {
  const oldDir = path.join(app.getPath('appData'), 'Atelier 3D'), newDir = path.join(app.getPath('appData'), 'Liagos 3D');
  if (fs.existsSync(oldDir) && !fs.existsSync(newDir)) app.setPath('userData', oldDir);
} catch (e) { /* on continue avec le dossier par défaut */ }

function createWindow() {
  const win = new BrowserWindow({
    width: 1280, height: 820, minWidth: 420, minHeight: 480,
    backgroundColor: '#101820', title: 'Liagos 3D', autoHideMenuBar: true,
    webPreferences: { contextIsolation: true, nodeIntegration: false }
  });
  Menu.setApplicationMenu(null);
  win.loadFile(path.join(__dirname, 'app', 'index.html'));
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https?:/.test(url)) shell.openExternal(url);
    return { action: 'deny' };
  });
}

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());
