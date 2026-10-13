<?php

declare(strict_types=1);

namespace SpacedTracker\Catalog;

enum RequestClass: string
{
    case Announcement = 'basicspacedata.announcement';
    case Boxscore = 'basicspacedata.boxscore';
    case CdmPublic = 'basicspacedata.cdm_public';
    case Decay = 'basicspacedata.decay';
    case Gp = 'basicspacedata.gp';
    case GpHistory = 'basicspacedata.gp_history';
    case LaunchSite = 'basicspacedata.launch_site';
    case Satcat = 'basicspacedata.satcat';
    case SatcatChange = 'basicspacedata.satcat_change';
    case SatcatDebut = 'basicspacedata.satcat_debut';
    case Tip = 'basicspacedata.tip';
    case Tle = 'basicspacedata.tle';
    case TleLatest = 'basicspacedata.tle_latest';
    case TlePublish = 'basicspacedata.tle_publish';
    case Omm = 'basicspacedata.omm';
    case Car = 'expandedspacedata.car';
    case Cdm = 'expandedspacedata.cdm';
    case Maneuver = 'expandedspacedata.maneuver';
    case ManeuverHistory = 'expandedspacedata.maneuver_history';
    case Organization = 'expandedspacedata.organization';
    case Satellite = 'expandedspacedata.satellite';
    case Csm = 'expandedspacedata.csm';
    case FileShareDelete = 'fileshare.delete';
    case FileShareDownload = 'fileshare.download';
    case FileShareFile = 'fileshare.file';
    case FileShareFolder = 'fileshare.folder';
    case FileShareUpload = 'fileshare.upload';
    case EphemerisDownload = 'spephemeris.download';
    case EphemerisFile = 'spephemeris.file';
    case EphemerisFileHistory = 'spephemeris.file_history';
    case PublicDirs = 'publicfiles.dirs';
    case PublicDownload = 'publicfiles.download';

    public function wireName(): string
    {
        return substr($this->value, (int) strpos($this->value, '.') + 1);
    }

    public function controller(): Controller
    {
        return Controller::from(substr($this->value, 0, (int) strpos($this->value, '.')));
    }

    public function isDeprecated(): bool
    {
        return match ($this) {
            self::Tle, self::TleLatest, self::TlePublish, self::Omm, self::Csm => true,
            default => false,
        };
    }

    public function httpMethod(): string
    {
        return $this === self::FileShareUpload ? 'POST' : 'GET';
    }

    /**
     * Fields that Space-Track accepts on the query string instead of the path.
     *
     * @return list<string>
     */
    public function queryParameters(): array
    {
        return match ($this) {
            self::PublicDownload => ['name'],
            default => [],
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Announcement => 'Operator announcements posted on Space-Track.',
            self::Boxscore => 'Daily boxscore counts of payloads, rocket bodies, and debris by country.',
            self::CdmPublic => 'Public conjunction data messages.',
            self::Decay => 'Decay messages and historical reentry records.',
            self::Gp => 'Current general perturbations element sets (OMM / TLE).',
            self::GpHistory => 'Archived general perturbations element sets.',
            self::LaunchSite => 'Launch site codes and names.',
            self::Satcat => 'Satellite catalog records for tracked objects.',
            self::SatcatChange => 'Recent satellite catalog field changes.',
            self::SatcatDebut => 'Objects newly added to the satellite catalog.',
            self::Tip => 'Tracking and Impact Prediction reentry messages.',
            self::Tle => 'Legacy two-line element sets. Prefer GP.',
            self::TleLatest => 'Legacy latest TLE class. Prefer GP.',
            self::TlePublish => 'Legacy TLE publish class. Prefer GP.',
            self::Omm => 'Legacy OMM class. Prefer GP.',
            self::Car => 'Conjunction assessment data for approved accounts.',
            self::Cdm => 'Full conjunction data messages for approved accounts.',
            self::Maneuver => 'Current maneuver records for cooperative satellites.',
            self::ManeuverHistory => 'Historical maneuver records for cooperative satellites.',
            self::Organization => 'Organizations registered for expanded data.',
            self::Satellite => 'Expanded satellite records for owner/operator accounts.',
            self::Csm => 'Legacy conjunction screening messages. Prefer CDM.',
            self::FileShareDelete => 'Delete a file from the account file share.',
            self::FileShareDownload => 'Download a file from the account file share.',
            self::FileShareFile => 'List or inspect files in the account file share.',
            self::FileShareFolder => 'List folders in the account file share.',
            self::FileShareUpload => 'Upload a file into the account file share.',
            self::EphemerisDownload => 'Download a special-perturbations ephemeris file.',
            self::EphemerisFile => 'List special-perturbations ephemeris files.',
            self::EphemerisFileHistory => 'History of special-perturbations ephemeris files.',
            self::PublicDirs => 'List public file directories. Authentication is optional.',
            self::PublicDownload => 'Download a public file by name.',
        };
    }

    public static function resolve(string $class, ?string $controller = null): self
    {
        $class = strtolower($class);
        $matches = [];
        foreach (self::cases() as $case) {
            if ($case->wireName() === $class) {
                $matches[] = $case;
            }
        }

        if ($matches === []) {
            throw new \InvalidArgumentException(sprintf('Unknown Space-Track request class "%s".', $class));
        }

        if (count($matches) === 1 && ($controller === null || $matches[0]->controller()->value === strtolower($controller))) {
            return $matches[0];
        }

        if ($controller === null) {
            throw new \InvalidArgumentException(sprintf(
                'Request class "%s" belongs to more than one controller. Pass the controller explicitly.',
                $class,
            ));
        }

        $controller = strtolower($controller);
        foreach ($matches as $match) {
            if ($match->controller()->value === $controller) {
                return $match;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Controller "%s" does not provide request class "%s".',
            $controller,
            $class,
        ));
    }

    /**
     * @return list<array{id: string, class: string, controller: string, summary: string, deprecated: bool, method: string}>
     */
    public static function directory(): array
    {
        $rows = [];
        foreach (self::cases() as $case) {
            $rows[] = [
                'id' => $case->value,
                'class' => $case->wireName(),
                'controller' => $case->controller()->value,
                'summary' => $case->summary(),
                'deprecated' => $case->isDeprecated(),
                'method' => $case->httpMethod(),
            ];
        }

        return $rows;
    }
}
