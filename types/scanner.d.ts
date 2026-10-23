// Nur für die IDE (PhpStorm); wird nicht ausgeliefert: Typen für den Barcode-Scan in public/app.js, die PhpStorm
// sonst nicht kennt. BarcodeDetector ist noch nicht in den Standard-Typen des Browsers, ZXing wird erst zur Laufzeit
// aus public/vendor/zxing.min.js geladen. Deklariert ist nur, was app.js benutzt.

/** Ein erkannter Barcode (Shape Detection API). */
interface DetectedBarcode {
    rawValue: string;
    format: string;
}

/** Eingebauter Barcode-Leser des Browsers, u. a. in Chrome auf Android; fehlt in Safari und Firefox. */
declare class BarcodeDetector {
    constructor(options?: { formats: string[] });
    static getSupportedFormats(): Promise<string[]>;
    detect(source: ImageBitmapSource): Promise<DetectedBarcode[]>;
}

/** @zxing/library 0.21.3 (UMD-Build setzt window.ZXing). */
declare namespace ZXing {
    enum DecodeHintType {
        POSSIBLE_FORMATS = 2,
    }

    enum BarcodeFormat {
        EAN_8 = 6,
        EAN_13 = 7,
        UPC_A = 14,
    }

    interface Result {
        getText(): string;
    }

    class BrowserMultiFormatReader {
        constructor(hints?: Map<DecodeHintType, unknown>, timeBetweenScansMillis?: number);
        decodeFromConstraints(
            constraints: MediaStreamConstraints,
            videoSource: HTMLVideoElement,
            callbackFn: (result: Result | null, error?: Error) => void,
        ): Promise<void>;
        reset(): void;
    }
}

// Ergänzt das Window des Browsers (gleichnamige Interfaces verschmelzen); PhpStorm hält es sonst für unbenutzt.
// noinspection JSUnusedGlobalSymbols
interface Window {
    BarcodeDetector?: typeof BarcodeDetector;
    ZXing?: typeof ZXing;
}
