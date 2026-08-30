// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for shillinq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import AccountArrowRightOutline from 'vue-material-design-icons/AccountArrowRightOutline.vue'
import AccountCancelOutline from 'vue-material-design-icons/AccountCancelOutline.vue'
import AccountCashOutline from 'vue-material-design-icons/AccountCashOutline.vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountHardHatOutline from 'vue-material-design-icons/AccountHardHatOutline.vue'
import AccountKey from 'vue-material-design-icons/AccountKey.vue'
import AccountKeyOutline from 'vue-material-design-icons/AccountKeyOutline.vue'
import AccountLockOutline from 'vue-material-design-icons/AccountLockOutline.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountStarOutline from 'vue-material-design-icons/AccountStarOutline.vue'
import AccountSwitch from 'vue-material-design-icons/AccountSwitch.vue'
import AccountSwitchOutline from 'vue-material-design-icons/AccountSwitchOutline.vue'
import AccountTie from 'vue-material-design-icons/AccountTie.vue'
import AccountTieOutline from 'vue-material-design-icons/AccountTieOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ArchiveArrowDownOutline from 'vue-material-design-icons/ArchiveArrowDownOutline.vue'
import ArchiveArrowUpOutline from 'vue-material-design-icons/ArchiveArrowUpOutline.vue'
import ArrowDecisionOutline from 'vue-material-design-icons/ArrowDecisionOutline.vue'
import ArrowDownBoldOutline from 'vue-material-design-icons/ArrowDownBoldOutline.vue'
import ArrowRightBoldOutline from 'vue-material-design-icons/ArrowRightBoldOutline.vue'
import ArrowUpDown from 'vue-material-design-icons/ArrowUpDown.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import Bank from 'vue-material-design-icons/Bank.vue'
import BankCheck from 'vue-material-design-icons/BankCheck.vue'
import BankCircle from 'vue-material-design-icons/BankCircle.vue'
import BankMinus from 'vue-material-design-icons/BankMinus.vue'
import BankOutline from 'vue-material-design-icons/BankOutline.vue'
import BankTransfer from 'vue-material-design-icons/BankTransfer.vue'
import BankTransferIn from 'vue-material-design-icons/BankTransferIn.vue'
import BankTransferOut from 'vue-material-design-icons/BankTransferOut.vue'
import Barcode from 'vue-material-design-icons/Barcode.vue'
import BarcodeScan from 'vue-material-design-icons/BarcodeScan.vue'
import Bell from 'vue-material-design-icons/Bell.vue'
import BellOffOutline from 'vue-material-design-icons/BellOffOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BellRingOutline from 'vue-material-design-icons/BellRingOutline.vue'
import BookEditOutline from 'vue-material-design-icons/BookEditOutline.vue'
import BookmarkCheckOutline from 'vue-material-design-icons/BookmarkCheckOutline.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import BookMinusOutline from 'vue-material-design-icons/BookMinusOutline.vue'
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import BookOutline from 'vue-material-design-icons/BookOutline.vue'
import BookRemoveOutline from 'vue-material-design-icons/BookRemoveOutline.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import BriefcaseAccountOutline from 'vue-material-design-icons/BriefcaseAccountOutline.vue'
import BriefcaseCheckOutline from 'vue-material-design-icons/BriefcaseCheckOutline.vue'
import BriefcaseClockOutline from 'vue-material-design-icons/BriefcaseClockOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import BriefcaseUpload from 'vue-material-design-icons/BriefcaseUpload.vue'
import BullseyeArrow from 'vue-material-design-icons/BullseyeArrow.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import CalculatorVariantOutline from 'vue-material-design-icons/CalculatorVariantOutline.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarAccountOutline from 'vue-material-design-icons/CalendarAccountOutline.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import CalendarLock from 'vue-material-design-icons/CalendarLock.vue'
import CalendarMinusOutline from 'vue-material-design-icons/CalendarMinusOutline.vue'
import CalendarMultiselect from 'vue-material-design-icons/CalendarMultiselect.vue'
import CalendarOutline from 'vue-material-design-icons/CalendarOutline.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import CalendarRangeOutline from 'vue-material-design-icons/CalendarRangeOutline.vue'
import CalendarRemoveOutline from 'vue-material-design-icons/CalendarRemoveOutline.vue'
import CalendarSync from 'vue-material-design-icons/CalendarSync.vue'
import CalendarSyncOutline from 'vue-material-design-icons/CalendarSyncOutline.vue'
import CalendarTextOutline from 'vue-material-design-icons/CalendarTextOutline.vue'
import CalendarWeekOutline from 'vue-material-design-icons/CalendarWeekOutline.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CardAccountDetailsOutline from 'vue-material-design-icons/CardAccountDetailsOutline.vue'
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'
import CarOutline from 'vue-material-design-icons/CarOutline.vue'
import CartArrowDown from 'vue-material-design-icons/CartArrowDown.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import CashCheck from 'vue-material-design-icons/CashCheck.vue'
import CashClock from 'vue-material-design-icons/CashClock.vue'
import CashFast from 'vue-material-design-icons/CashFast.vue'
import CashLock from 'vue-material-design-icons/CashLock.vue'
import CashMinus from 'vue-material-design-icons/CashMinus.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import CashPlus from 'vue-material-design-icons/CashPlus.vue'
import CashRefund from 'vue-material-design-icons/CashRefund.vue'
import CashSync from 'vue-material-design-icons/CashSync.vue'
import CertificateOutline from 'vue-material-design-icons/CertificateOutline.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ChartBarStacked from 'vue-material-design-icons/ChartBarStacked.vue'
import ChartBellCurve from 'vue-material-design-icons/ChartBellCurve.vue'
import ChartBellCurveCumulative from 'vue-material-design-icons/ChartBellCurveCumulative.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartBubble from 'vue-material-design-icons/ChartBubble.vue'
import ChartDonutVariant from 'vue-material-design-icons/ChartDonutVariant.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChartLineVariant from 'vue-material-design-icons/ChartLineVariant.vue'
import ChartPie from 'vue-material-design-icons/ChartPie.vue'
import ChartScatterPlot from 'vue-material-design-icons/ChartScatterPlot.vue'
import ChartTimelineVariant from 'vue-material-design-icons/ChartTimelineVariant.vue'
import ChartTimelineVariantShimmer from 'vue-material-design-icons/ChartTimelineVariantShimmer.vue'
import ChartWaterfall from 'vue-material-design-icons/ChartWaterfall.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import ClipboardArrowDownOutline from 'vue-material-design-icons/ClipboardArrowDownOutline.vue'
import ClipboardArrowRightOutline from 'vue-material-design-icons/ClipboardArrowRightOutline.vue'
import ClipboardCheckMultipleOutline from 'vue-material-design-icons/ClipboardCheckMultipleOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardTextClockOutline from 'vue-material-design-icons/ClipboardTextClockOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import ClipboardTextSearchOutline from 'vue-material-design-icons/ClipboardTextSearchOutline.vue'
import ClockAlertOutline from 'vue-material-design-icons/ClockAlertOutline.vue'
import ClockCheckOutline from 'vue-material-design-icons/ClockCheckOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import ClockTimeFourOutline from 'vue-material-design-icons/ClockTimeFourOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import CoffeeOutline from 'vue-material-design-icons/CoffeeOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import Compare from 'vue-material-design-icons/Compare.vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import Counter from 'vue-material-design-icons/Counter.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import CurrencyUsd from 'vue-material-design-icons/CurrencyUsd.vue'
import DatabaseArrowDown from 'vue-material-design-icons/DatabaseArrowDown.vue'
import DatabaseClock from 'vue-material-design-icons/DatabaseClock.vue'
import DatabaseExportOutline from 'vue-material-design-icons/DatabaseExportOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import DesktopTowerMonitor from 'vue-material-design-icons/DesktopTowerMonitor.vue'
import DiamondStone from 'vue-material-design-icons/DiamondStone.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import DownloadOutline from 'vue-material-design-icons/DownloadOutline.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import EarthArrowRight from 'vue-material-design-icons/EarthArrowRight.vue'
import EarthBox from 'vue-material-design-icons/EarthBox.vue'
import EmailAlertOutline from 'vue-material-design-icons/EmailAlertOutline.vue'
import EmailCheckOutline from 'vue-material-design-icons/EmailCheckOutline.vue'
import EmailFastOutline from 'vue-material-design-icons/EmailFastOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EmailRemoveOutline from 'vue-material-design-icons/EmailRemoveOutline.vue'
import Factory from 'vue-material-design-icons/Factory.vue'
import FamilyTree from 'vue-material-design-icons/FamilyTree.vue'
import FileAlertOutline from 'vue-material-design-icons/FileAlertOutline.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'
import FileChartCheckOutline from 'vue-material-design-icons/FileChartCheckOutline.vue'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import FileCheckOutline from 'vue-material-design-icons/FileCheckOutline.vue'
import FileClockOutline from 'vue-material-design-icons/FileClockOutline.vue'
import FileCogOutline from 'vue-material-design-icons/FileCogOutline.vue'
import FileCompare from 'vue-material-design-icons/FileCompare.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentMinusOutline from 'vue-material-design-icons/FileDocumentMinusOutline.vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileDocumentPlus from 'vue-material-design-icons/FileDocumentPlus.vue'
import FileDocumentRemoveOutline from 'vue-material-design-icons/FileDocumentRemoveOutline.vue'
import FileDownloadOutline from 'vue-material-design-icons/FileDownloadOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileImportOutline from 'vue-material-design-icons/FileImportOutline.vue'
import FileLockOutline from 'vue-material-design-icons/FileLockOutline.vue'
import FileRemoveOutline from 'vue-material-design-icons/FileRemoveOutline.vue'
import FileReplaceOutline from 'vue-material-design-icons/FileReplaceOutline.vue'
import FileSendOutline from 'vue-material-design-icons/FileSendOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import FileXmlBox from 'vue-material-design-icons/FileXmlBox.vue'
import FilterCogOutline from 'vue-material-design-icons/FilterCogOutline.vue'
import Finance from 'vue-material-design-icons/Finance.vue'
import FlagVariantOutline from 'vue-material-design-icons/FlagVariantOutline.vue'
import FlashOutline from 'vue-material-design-icons/FlashOutline.vue'
import FlaskOutline from 'vue-material-design-icons/FlaskOutline.vue'
import FolderLockOutline from 'vue-material-design-icons/FolderLockOutline.vue'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderTextOutline from 'vue-material-design-icons/FolderTextOutline.vue'
import FolderZipOutline from 'vue-material-design-icons/FolderZipOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListBulletedSquare from 'vue-material-design-icons/FormatListBulletedSquare.vue'
import FormatListBulletedType from 'vue-material-design-icons/FormatListBulletedType.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatListGroup from 'vue-material-design-icons/FormatListGroup.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatListNumberedRtl from 'vue-material-design-icons/FormatListNumberedRtl.vue'
import Gauge from 'vue-material-design-icons/Gauge.vue'
import GaugeFull from 'vue-material-design-icons/GaugeFull.vue'
import GaugeLow from 'vue-material-design-icons/GaugeLow.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import HandCoinOutline from 'vue-material-design-icons/HandCoinOutline.vue'
import HandHeartOutline from 'vue-material-design-icons/HandHeartOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import HelpRhombusOutline from 'vue-material-design-icons/HelpRhombusOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import Leaf from 'vue-material-design-icons/Leaf.vue'
import LeafCircleOutline from 'vue-material-design-icons/LeafCircleOutline.vue'
import Lightbulb from 'vue-material-design-icons/Lightbulb.vue'
import LightbulbOnOutline from 'vue-material-design-icons/LightbulbOnOutline.vue'
import LightbulbOutline from 'vue-material-design-icons/LightbulbOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import MapOutline from 'vue-material-design-icons/MapOutline.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import Molecule from 'vue-material-design-icons/Molecule.vue'
import NotebookOutline from 'vue-material-design-icons/NotebookOutline.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OfficeBuildingMarkerOutline from 'vue-material-design-icons/OfficeBuildingMarkerOutline.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import PackageVariant from 'vue-material-design-icons/PackageVariant.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import Percent from 'vue-material-design-icons/Percent.vue'
import PercentOutline from 'vue-material-design-icons/PercentOutline.vue'
import PiggyBankOutline from 'vue-material-design-icons/PiggyBankOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import PlusCircle from 'vue-material-design-icons/PlusCircle.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import Pulse from 'vue-material-design-icons/Pulse.vue'
import ReceiptOutline from 'vue-material-design-icons/ReceiptOutline.vue'
import ReceiptTextMinusOutline from 'vue-material-design-icons/ReceiptTextMinusOutline.vue'
import ReceiptTextOutline from 'vue-material-design-icons/ReceiptTextOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import RepeatVariant from 'vue-material-design-icons/RepeatVariant.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import RoadVariant from 'vue-material-design-icons/RoadVariant.vue'
import RssBox from 'vue-material-design-icons/RssBox.vue'
import RunFast from 'vue-material-design-icons/RunFast.vue'
import SaleOutline from 'vue-material-design-icons/SaleOutline.vue'
import Scale from 'vue-material-design-icons/Scale.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import ShieldAlertOutline from 'vue-material-design-icons/ShieldAlertOutline.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldCrownOutline from 'vue-material-design-icons/ShieldCrownOutline.vue'
import ShieldEditOutline from 'vue-material-design-icons/ShieldEditOutline.vue'
import ShieldHalfFull from 'vue-material-design-icons/ShieldHalfFull.vue'
import ShieldOutline from 'vue-material-design-icons/ShieldOutline.vue'
import ShieldPlusOutline from 'vue-material-design-icons/ShieldPlusOutline.vue'
import ShieldRemoveOutline from 'vue-material-design-icons/ShieldRemoveOutline.vue'
import ShieldSearch from 'vue-material-design-icons/ShieldSearch.vue'
import ShoppingOutline from 'vue-material-design-icons/ShoppingOutline.vue'
import Sigma from 'vue-material-design-icons/Sigma.vue'
import SignatureFreehand from 'vue-material-design-icons/SignatureFreehand.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import SolarPower from 'vue-material-design-icons/SolarPower.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import SpeedometerSlow from 'vue-material-design-icons/SpeedometerSlow.vue'
import StairsUp from 'vue-material-design-icons/StairsUp.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import SwapHorizontalBold from 'vue-material-design-icons/SwapHorizontalBold.vue'
import SwapVerticalBold from 'vue-material-design-icons/SwapVerticalBold.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import TableAccount from 'vue-material-design-icons/TableAccount.vue'
import TableArrowRight from 'vue-material-design-icons/TableArrowRight.vue'
import TableClock from 'vue-material-design-icons/TableClock.vue'
import TableColumn from 'vue-material-design-icons/TableColumn.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import TableRow from 'vue-material-design-icons/TableRow.vue'
import TableRowPlusAfter from 'vue-material-design-icons/TableRowPlusAfter.vue'
import TagCheckOutline from 'vue-material-design-icons/TagCheckOutline.vue'
import TagMultipleOutline from 'vue-material-design-icons/TagMultipleOutline.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import TextBoxCheckOutline from 'vue-material-design-icons/TextBoxCheckOutline.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import TicketConfirmationOutline from 'vue-material-design-icons/TicketConfirmationOutline.vue'
import TimerSandEmpty from 'vue-material-design-icons/TimerSandEmpty.vue'
import TransferRight from 'vue-material-design-icons/TransferRight.vue'
import TransitConnectionVariant from 'vue-material-design-icons/TransitConnectionVariant.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import TrendingDown from 'vue-material-design-icons/TrendingDown.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import TrophyOutline from 'vue-material-design-icons/TrophyOutline.vue'
import Truck from 'vue-material-design-icons/Truck.vue'
import TruckDeliveryOutline from 'vue-material-design-icons/TruckDeliveryOutline.vue'
import TruckOutline from 'vue-material-design-icons/TruckOutline.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import TuneVariant from 'vue-material-design-icons/TuneVariant.vue'
import UndoVariant from 'vue-material-design-icons/UndoVariant.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import VectorDifference from 'vue-material-design-icons/VectorDifference.vue'
import VectorIntersection from 'vue-material-design-icons/VectorIntersection.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import ViewListOutline from 'vue-material-design-icons/ViewListOutline.vue'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import Wallet from 'vue-material-design-icons/Wallet.vue'
import WalletOutline from 'vue-material-design-icons/WalletOutline.vue'
import Warehouse from 'vue-material-design-icons/Warehouse.vue'
import WaterCheckOutline from 'vue-material-design-icons/WaterCheckOutline.vue'
import WaterOutline from 'vue-material-design-icons/WaterOutline.vue'

export default {
	AccountArrowRightOutline,
	AccountCancelOutline,
	AccountCashOutline,
	AccountCheckOutline,
	AccountGroup,
	AccountGroupOutline,
	AccountHardHatOutline,
	AccountKey,
	AccountKeyOutline,
	AccountLockOutline,
	AccountMultipleOutline,
	AccountStarOutline,
	AccountSwitch,
	AccountSwitchOutline,
	AccountTie,
	AccountTieOutline,
	AlertCircleOutline,
	AlertOctagonOutline,
	AlertOutline,
	ArchiveArrowDownOutline,
	ArchiveArrowUpOutline,
	ArrowDecisionOutline,
	ArrowDownBoldOutline,
	ArrowRightBoldOutline,
	ArrowUpDown,
	AutoFix,
	Bank,
	BankCheck,
	BankCircle,
	BankMinus,
	BankOutline,
	BankTransfer,
	BankTransferIn,
	BankTransferOut,
	Barcode,
	BarcodeScan,
	Bell,
	BellOffOutline,
	BellOutline,
	BellRingOutline,
	BookEditOutline,
	BookMinusOutline,
	BookOpenPageVariantOutline,
	BookOpenVariant,
	BookOpenVariantOutline,
	BookOutline,
	BookRemoveOutline,
	BookmarkCheckOutline,
	BookmarkOutline,
	Briefcase,
	BriefcaseAccountOutline,
	BriefcaseCheckOutline,
	BriefcaseClockOutline,
	BriefcaseOutline,
	BriefcaseUpload,
	BullseyeArrow,
	Calculator,
	CalculatorVariantOutline,
	Calendar,
	CalendarAccountOutline,
	CalendarCheckOutline,
	CalendarClock,
	CalendarClockOutline,
	CalendarLock,
	CalendarMinusOutline,
	CalendarMultiselect,
	CalendarOutline,
	CalendarRange,
	CalendarRangeOutline,
	CalendarRemoveOutline,
	CalendarSync,
	CalendarSyncOutline,
	CalendarTextOutline,
	CalendarWeekOutline,
	CallSplit,
	Cancel,
	CarOutline,
	CardAccountDetailsOutline,
	CardTextOutline,
	CartArrowDown,
	CartOutline,
	Cash,
	CashCheck,
	CashClock,
	CashFast,
	CashLock,
	CashMinus,
	CashMultiple,
	CashPlus,
	CashRefund,
	CashSync,
	CertificateOutline,
	ChartBar,
	ChartBarStacked,
	ChartBellCurve,
	ChartBellCurveCumulative,
	ChartBoxOutline,
	ChartBubble,
	ChartDonutVariant,
	ChartLine,
	ChartLineVariant,
	ChartPie,
	ChartScatterPlot,
	ChartTimelineVariant,
	ChartTimelineVariantShimmer,
	ChartWaterfall,
	CheckCircleOutline,
	CheckDecagramOutline,
	CheckboxMarkedOutline,
	ClipboardArrowDownOutline,
	ClipboardArrowRightOutline,
	ClipboardCheckMultipleOutline,
	ClipboardCheckOutline,
	ClipboardList,
	ClipboardListOutline,
	ClipboardTextClockOutline,
	ClipboardTextOutline,
	ClipboardTextSearchOutline,
	ClockAlertOutline,
	ClockCheckOutline,
	ClockOutline,
	ClockTimeFourOutline,
	CloseCircleOutline,
	CloudUploadOutline,
	CoffeeOutline,
	Cog,
	CogOutline,
	CommentOutline,
	Compare,
	CompareHorizontal,
	Counter,
	CreditCardOutline,
	CubeOutline,
	CurrencyEur,
	CurrencyUsd,
	DatabaseArrowDown,
	DatabaseClock,
	DatabaseExportOutline,
	DatabaseImportOutline,
	DatabaseOutline,
	DeleteSweepOutline,
	DesktopTowerMonitor,
	DiamondStone,
	Domain,
	DownloadOutline,
	Earth,
	EarthArrowRight,
	EarthBox,
	EmailAlertOutline,
	EmailCheckOutline,
	EmailFastOutline,
	EmailOutline,
	EmailRemoveOutline,
	Factory,
	FamilyTree,
	FileAlertOutline,
	FileCertificateOutline,
	FileChartCheckOutline,
	FileChartOutline,
	FileCheckOutline,
	FileClockOutline,
	FileCogOutline,
	FileCompare,
	FileDocument,
	FileDocumentCheckOutline,
	FileDocumentEdit,
	FileDocumentEditOutline,
	FileDocumentMinusOutline,
	FileDocumentMultiple,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	FileDocumentPlus,
	FileDocumentRemoveOutline,
	FileDownloadOutline,
	FileExportOutline,
	FileImportOutline,
	FileLockOutline,
	FileRemoveOutline,
	FileReplaceOutline,
	FileSendOutline,
	FileSign,
	FileTreeOutline,
	FileXmlBox,
	FilterCogOutline,
	Finance,
	FlagVariantOutline,
	FlashOutline,
	FlaskOutline,
	FolderLockOutline,
	FolderOpenOutline,
	FolderOutline,
	FolderTextOutline,
	FolderZipOutline,
	FormatListBulleted,
	FormatListBulletedSquare,
	FormatListBulletedType,
	FormatListChecks,
	FormatListGroup,
	FormatListNumbered,
	FormatListNumberedRtl,
	Gauge,
	GaugeFull,
	GaugeLow,
	Gavel,
	HandCoinOutline,
	HandHeartOutline,
	HandshakeOutline,
	HelpRhombusOutline,
	History,
	InboxArrowDown,
	KeyOutline,
	Leaf,
	LeafCircleOutline,
	Lightbulb,
	LightbulbOnOutline,
	LightbulbOutline,
	LinkVariant,
	Lock,
	LockOutline,
	MapMarkerOutline,
	MapMarkerPath,
	MapOutline,
	MessageTextOutline,
	Molecule,
	NoteTextOutline,
	NotebookOutline,
	OfficeBuilding,
	OfficeBuildingMarkerOutline,
	OfficeBuildingOutline,
	PackageVariant,
	PackageVariantClosed,
	PauseCircleOutline,
	Percent,
	PercentOutline,
	PiggyBankOutline,
	PlayCircleOutline,
	PlusCircle,
	Poll,
	Pulse,
	ReceiptOutline,
	ReceiptTextMinusOutline,
	ReceiptTextOutline,
	Refresh,
	RepeatVariant,
	Restore,
	RoadVariant,
	RssBox,
	RunFast,
	SaleOutline,
	Scale,
	ScaleBalance,
	SendOutline,
	ShapeOutline,
	ShieldAccountOutline,
	ShieldAlertOutline,
	ShieldCheckOutline,
	ShieldCrownOutline,
	ShieldEditOutline,
	ShieldHalfFull,
	ShieldOutline,
	ShieldPlusOutline,
	ShieldRemoveOutline,
	ShieldSearch,
	ShoppingOutline,
	Sigma,
	SignatureFreehand,
	Sitemap,
	SitemapOutline,
	SolarPower,
	SourceBranch,
	SpeedometerSlow,
	StairsUp,
	StarOutline,
	StoreOutline,
	SwapHorizontal,
	SwapHorizontalBold,
	SwapVerticalBold,
	Sync,
	TableAccount,
	TableArrowRight,
	TableClock,
	TableColumn,
	TableLarge,
	TableMergeCells,
	TableRow,
	TableRowPlusAfter,
	TagCheckOutline,
	TagMultipleOutline,
	TagOutline,
	TextBoxCheckOutline,
	TextBoxOutline,
	TicketConfirmationOutline,
	TimerSandEmpty,
	TransferRight,
	TransitConnectionVariant,
	TrayArrowDown,
	TrendingDown,
	TrendingUp,
	TrophyOutline,
	Truck,
	TruckDeliveryOutline,
	TruckOutline,
	Tune,
	TuneVariant,
	UndoVariant,
	Upload,
	VectorDifference,
	VectorIntersection,
	ViewDashboardOutline,
	ViewListOutline,
	VoteOutline,
	Wallet,
	WalletOutline,
	Warehouse,
	WaterCheckOutline,
	WaterOutline,
}
