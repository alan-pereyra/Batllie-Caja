/**
 * Batllie Caja - Módulo de Notificación Sonora
 * Utiliza Web Audio API para generar un sonido de campana de mostrador nítido y libre de fallos por red.
 */

(function(window) {
    'use strict';

    class CajaAudioAlert {
        constructor() {
            this.audioCtx = null;
            this.enabled = true;
            this.isUnlocked = false;
        }

        /**
         * Inicializar o reanudar el AudioContext tras la primera interacción del usuario
         */
        init() {
            if (!this.audioCtx) {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (AudioContext) {
                    this.audioCtx = new AudioContext();
                }
            }
            if (this.audioCtx && this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }
            this.isUnlocked = true;
        }

        /**
         * Desbloquear audio en la primera pulsación de pantalla/click
         */
        unlock() {
            this.init();
        }

        /**
         * Reproducir sonido de campana de restaurante/caja (Dual-tone ding-dong)
         */
        playNewOrderSound() {
            if (!this.enabled) return;

            try {
                this.init();
                if (!this.audioCtx) return;

                const now = this.audioCtx.currentTime;

                // Tono 1: Campana aguda clara (Frecuencia 880 Hz - Nota La5)
                this._createChimeTone(880, now, 0.45, 0.8);

                // Tono 2: Armónico resonante complementario (1320 Hz)
                this._createChimeTone(1320, now, 0.35, 0.4);

                // Tono 3: Segundo chime de campana (1174.66 Hz - Nota Re6) desfasado 0.18s
                this._createChimeTone(1174.66, now + 0.18, 0.65, 0.9);

                // Tono 4: Resonancia cálida inferior (587.33 Hz) desfasado 0.18s
                this._createChimeTone(587.33, now + 0.18, 0.5, 0.5);

            } catch (err) {
                console.warn('Batllie Caja: Error al reproducir audio', err);
            }
        }

        /**
         * Generador de onda sinusoidal con envolvente exponencial para simular metal/campana
         */
        _createChimeTone(freq, startTime, duration, volume) {
            const osc = this.audioCtx.createOscillator();
            const gain = this.audioCtx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, startTime);

            // Ataque instantáneo y caída exponencial realista de campana
            gain.gain.setValueAtTime(0.001, startTime);
            gain.gain.exponentialRampToValueAtTime(volume, startTime + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

            osc.connect(gain);
            gain.connect(this.audioCtx.destination);

            osc.start(startTime);
            osc.stop(startTime + duration);
        }

        /**
         * Activar o desactivar sonido
         */
        toggle() {
            this.enabled = !this.enabled;
            if (this.enabled) {
                this.init();
                this.playNewOrderSound(); // Sonido de confirmación
            }
            return this.enabled;
        }

        setEnabled(state) {
            this.enabled = !!state;
            if (this.enabled) {
                this.init();
            }
        }
    }

    // Exponer globalmente
    window.BatllieCajaAudio = new CajaAudioAlert();

})(window);
