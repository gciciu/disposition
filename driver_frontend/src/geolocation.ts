export type GeoPosition = {
  lat: number;
  lng: number;
};

export class GeolocationError extends Error {
  code: "unsupported" | "denied" | "unavailable" | "timeout";

  constructor(code: GeolocationError["code"], message: string) {
    super(message);
    this.name = "GeolocationError";
    this.code = code;
  }
}

export function getCurrentPosition(): Promise<GeoPosition> {
  if (typeof navigator === "undefined" || !navigator.geolocation) {
    return Promise.reject(
      new GeolocationError("unsupported", "Geolocation is not available"),
    );
  }

  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        resolve({
          lat: position.coords.latitude,
          lng: position.coords.longitude,
        });
      },
      (error) => {
        switch (error.code) {
          case error.PERMISSION_DENIED:
            reject(
              new GeolocationError(
                "denied",
                "Location permission was denied",
              ),
            );
            break;
          case error.TIMEOUT:
            reject(
              new GeolocationError("timeout", "Location request timed out"),
            );
            break;
          default:
            reject(
              new GeolocationError(
                "unavailable",
                "Could not determine current location",
              ),
            );
        }
      },
      {
        enableHighAccuracy: true,
        timeout: 20000,
        maximumAge: 0,
      },
    );
  });
}
