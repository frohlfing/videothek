// Nur für die IDE (PhpStorm); wird nicht ausgeliefert: Typen für den Browser-Player in public/app.js (playInBrowser(),
// askFolder()). Die File System Access API (Chrome, Edge) fehlt in den Standard-Typen des Browsers bzw. kennt dort
// queryPermission()/requestPermission() nicht. Deklariert ist nur, was app.js benutzt.

/** Art des Zugriffs auf einen Ordner bzw. eine Datei. */
interface FileSystemHandlePermissionDescriptor {
    mode?: 'read' | 'readwrite';
}

interface FileSystemHandle {
    /** Ob die Seite schon zugreifen darf, ohne zu fragen. */
    queryPermission(descriptor?: FileSystemHandlePermissionDescriptor): Promise<PermissionState>;
    /** Fragt nach dem Zugriff (nach einem Neustart des Browsers bei einem gemerkten Ordner). */
    requestPermission(descriptor?: FileSystemHandlePermissionDescriptor): Promise<PermissionState>;
}

interface DirectoryPickerOptions {
    /** Merkt sich den zuletzt gewählten Ordner je id. */
    id?: string;
    mode?: 'read' | 'readwrite';
    startIn?: FileSystemHandle | 'desktop' | 'documents' | 'downloads' | 'music' | 'pictures' | 'videos';
}

// Ergänzt das Window des Browsers (gleichnamige Interfaces verschmelzen); PhpStorm hält es sonst für unbenutzt.
// noinspection JSUnusedGlobalSymbols
interface Window {
    /** Ordnerauswahl des Browsers; fehlt in Firefox, Safari und auf Handys. */
    showDirectoryPicker(options?: DirectoryPickerOptions): Promise<FileSystemDirectoryHandle>;
}
