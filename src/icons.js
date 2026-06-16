// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// Central MDI icon registry for the manifest-driven navigation and pages.
// CnAppNav resolves a menu item's `icon` string through the lib's
// `registerIcons()` registry (ICON_MAP); any name missing from the registry
// silently renders NO icon. Every MDI name referenced by src/manifest.json
// or a src/manifest.d/*.json fragment therefore MUST be registered here.
// Names ending in the ALIASES block do not exist in vue-material-design-icons
// — they are mapped to the closest real icon so fragments keep working.

import AccountCashOutline from 'vue-material-design-icons/AccountCashOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountHardHatOutline from 'vue-material-design-icons/AccountHardHatOutline.vue'
import AccountKey from 'vue-material-design-icons/AccountKey.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountSwitch from 'vue-material-design-icons/AccountSwitch.vue'
import AccountSwitchOutline from 'vue-material-design-icons/AccountSwitchOutline.vue'
import AccountTie from 'vue-material-design-icons/AccountTie.vue'
import AccountTieOutline from 'vue-material-design-icons/AccountTieOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'
import ArrowDecisionOutline from 'vue-material-design-icons/ArrowDecisionOutline.vue'
import ArrowDownBoldOutline from 'vue-material-design-icons/ArrowDownBoldOutline.vue'
import ArrowRightBoldOutline from 'vue-material-design-icons/ArrowRightBoldOutline.vue'
import ArrowUpDown from 'vue-material-design-icons/ArrowUpDown.vue'
import Bank from 'vue-material-design-icons/Bank.vue'
import BankCheck from 'vue-material-design-icons/BankCheck.vue'
import BankOutline from 'vue-material-design-icons/BankOutline.vue'
import BankTransfer from 'vue-material-design-icons/BankTransfer.vue'
import Barcode from 'vue-material-design-icons/Barcode.vue'
import BarcodeScan from 'vue-material-design-icons/BarcodeScan.vue'
import BellRingOutline from 'vue-material-design-icons/BellRingOutline.vue'
import BookEditOutline from 'vue-material-design-icons/BookEditOutline.vue'
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import BookOutline from 'vue-material-design-icons/BookOutline.vue'
import BookmarkCheckOutline from 'vue-material-design-icons/BookmarkCheckOutline.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import BriefcaseAccountOutline from 'vue-material-design-icons/BriefcaseAccountOutline.vue'
import BriefcaseCheckOutline from 'vue-material-design-icons/BriefcaseCheckOutline.vue'
import BriefcaseClockOutline from 'vue-material-design-icons/BriefcaseClockOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import CalculatorVariantOutline from 'vue-material-design-icons/CalculatorVariantOutline.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import CalendarLock from 'vue-material-design-icons/CalendarLock.vue'
import CalendarMultiselect from 'vue-material-design-icons/CalendarMultiselect.vue'
import CalendarOutline from 'vue-material-design-icons/CalendarOutline.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import CalendarRemoveOutline from 'vue-material-design-icons/CalendarRemoveOutline.vue'
import CalendarSync from 'vue-material-design-icons/CalendarSync.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import CarOutline from 'vue-material-design-icons/CarOutline.vue'
import CartArrowDown from 'vue-material-design-icons/CartArrowDown.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import CashRefund from 'vue-material-design-icons/CashRefund.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ChartBarStacked from 'vue-material-design-icons/ChartBarStacked.vue'
import ChartBellCurve from 'vue-material-design-icons/ChartBellCurve.vue'
import ChartBellCurveCumulative from 'vue-material-design-icons/ChartBellCurveCumulative.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartBubble from 'vue-material-design-icons/ChartBubble.vue'
import ChartDonutVariant from 'vue-material-design-icons/ChartDonutVariant.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChartLineVariant from 'vue-material-design-icons/ChartLineVariant.vue'
import ChartTimelineVariant from 'vue-material-design-icons/ChartTimelineVariant.vue'
import ChartTimelineVariantShimmer from 'vue-material-design-icons/ChartTimelineVariantShimmer.vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardTextClockOutline from 'vue-material-design-icons/ClipboardTextClockOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import ClipboardTextSearchOutline from 'vue-material-design-icons/ClipboardTextSearchOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import CurrencyUsd from 'vue-material-design-icons/CurrencyUsd.vue'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import DownloadOutline from 'vue-material-design-icons/DownloadOutline.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import EarthArrowRight from 'vue-material-design-icons/EarthArrowRight.vue'
import EarthBox from 'vue-material-design-icons/EarthBox.vue'
import EmailAlertOutline from 'vue-material-design-icons/EmailAlertOutline.vue'
import EmailCheckOutline from 'vue-material-design-icons/EmailCheckOutline.vue'
import EmailFastOutline from 'vue-material-design-icons/EmailFastOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EmailRemoveOutline from 'vue-material-design-icons/EmailRemoveOutline.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'
import FileChartCheckOutline from 'vue-material-design-icons/FileChartCheckOutline.vue'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import FileRemoveOutline from 'vue-material-design-icons/FileRemoveOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import FileXmlBox from 'vue-material-design-icons/FileXmlBox.vue'
import FilterCogOutline from 'vue-material-design-icons/FilterCogOutline.vue'
import Finance from 'vue-material-design-icons/Finance.vue'
import FlaskOutline from 'vue-material-design-icons/FlaskOutline.vue'
import FolderLockOutline from 'vue-material-design-icons/FolderLockOutline.vue'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import GaugeFull from 'vue-material-design-icons/GaugeFull.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import HandCoinOutline from 'vue-material-design-icons/HandCoinOutline.vue'
import HandHeartOutline from 'vue-material-design-icons/HandHeartOutline.vue'
import HelpRhombusOutline from 'vue-material-design-icons/HelpRhombusOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import Leaf from 'vue-material-design-icons/Leaf.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import MapOutline from 'vue-material-design-icons/MapOutline.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import Molecule from 'vue-material-design-icons/Molecule.vue'
import NotebookOutline from 'vue-material-design-icons/NotebookOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import PackageVariant from 'vue-material-design-icons/PackageVariant.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import PercentOutline from 'vue-material-design-icons/PercentOutline.vue'
import PiggyBankOutline from 'vue-material-design-icons/PiggyBankOutline.vue'
import PlusCircle from 'vue-material-design-icons/PlusCircle.vue'
import Poll from 'vue-material-design-icons/Poll.vue'
import Pulse from 'vue-material-design-icons/Pulse.vue'
import ReceiptOutline from 'vue-material-design-icons/ReceiptOutline.vue'
import ReceiptTextOutline from 'vue-material-design-icons/ReceiptTextOutline.vue'
import RepeatVariant from 'vue-material-design-icons/RepeatVariant.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import RssBox from 'vue-material-design-icons/RssBox.vue'
import Scale from 'vue-material-design-icons/Scale.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import ShieldAlertOutline from 'vue-material-design-icons/ShieldAlertOutline.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldCrownOutline from 'vue-material-design-icons/ShieldCrownOutline.vue'
import ShieldHalfFull from 'vue-material-design-icons/ShieldHalfFull.vue'
import ShieldPlusOutline from 'vue-material-design-icons/ShieldPlusOutline.vue'
import ShieldRemoveOutline from 'vue-material-design-icons/ShieldRemoveOutline.vue'
import ShieldSearch from 'vue-material-design-icons/ShieldSearch.vue'
import ShoppingOutline from 'vue-material-design-icons/ShoppingOutline.vue'
import SignatureFreehand from 'vue-material-design-icons/SignatureFreehand.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import SpeedometerSlow from 'vue-material-design-icons/SpeedometerSlow.vue'
import StairsUp from 'vue-material-design-icons/StairsUp.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import SwapHorizontalBold from 'vue-material-design-icons/SwapHorizontalBold.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import TableAccount from 'vue-material-design-icons/TableAccount.vue'
import TableArrowRight from 'vue-material-design-icons/TableArrowRight.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import TagCheckOutline from 'vue-material-design-icons/TagCheckOutline.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import TransferRight from 'vue-material-design-icons/TransferRight.vue'
import TransitConnectionVariant from 'vue-material-design-icons/TransitConnectionVariant.vue'
import Truck from 'vue-material-design-icons/Truck.vue'
import TruckOutline from 'vue-material-design-icons/TruckOutline.vue'
import VectorDifference from 'vue-material-design-icons/VectorDifference.vue'
import VectorIntersection from 'vue-material-design-icons/VectorIntersection.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import Wallet from 'vue-material-design-icons/Wallet.vue'
import WalletOutline from 'vue-material-design-icons/WalletOutline.vue'
import Warehouse from 'vue-material-design-icons/Warehouse.vue'
import WaterCheckOutline from 'vue-material-design-icons/WaterCheckOutline.vue'
import WaterOutline from 'vue-material-design-icons/WaterOutline.vue'

export default {
	AccountCashOutline,
	AccountGroup,
	AccountGroupOutline,
	AccountHardHatOutline,
	AccountKey,
	AccountMultipleOutline,
	AccountSwitch,
	AccountSwitchOutline,
	AccountTie,
	AccountTieOutline,
	AlertCircleOutline,
	AlertOctagonOutline,
	ArrowDecisionOutline,
	ArrowDownBoldOutline,
	ArrowRightBoldOutline,
	ArrowUpDown,
	Bank,
	BankCheck,
	BankOutline,
	BankTransfer,
	Barcode,
	BarcodeScan,
	BellRingOutline,
	BookEditOutline,
	BookOpenPageVariantOutline,
	BookOpenVariant,
	BookOpenVariantOutline,
	BookOutline,
	BookmarkCheckOutline,
	BookmarkOutline,
	BriefcaseAccountOutline,
	BriefcaseCheckOutline,
	BriefcaseClockOutline,
	BriefcaseOutline,
	Calculator,
	CalculatorVariantOutline,
	CalendarCheckOutline,
	CalendarClock,
	CalendarClockOutline,
	CalendarLock,
	CalendarMultiselect,
	CalendarOutline,
	CalendarRange,
	CalendarRemoveOutline,
	CalendarSync,
	CallSplit,
	CarOutline,
	CartArrowDown,
	CartOutline,
	CashMultiple,
	CashRefund,
	ChartBar,
	ChartBarStacked,
	ChartBellCurve,
	ChartBellCurveCumulative,
	ChartBoxOutline,
	ChartBubble,
	ChartDonutVariant,
	ChartLine,
	ChartLineVariant,
	ChartTimelineVariant,
	ChartTimelineVariantShimmer,
	CheckDecagramOutline,
	ClipboardCheckOutline,
	ClipboardListOutline,
	ClipboardTextClockOutline,
	ClipboardTextOutline,
	ClipboardTextSearchOutline,
	ClockOutline,
	Cog,
	CogOutline,
	CreditCardOutline,
	CubeOutline,
	CurrencyEur,
	CurrencyUsd,
	DeleteSweepOutline,
	DownloadOutline,
	Earth,
	EarthArrowRight,
	EarthBox,
	EmailAlertOutline,
	EmailCheckOutline,
	EmailFastOutline,
	EmailOutline,
	EmailRemoveOutline,
	FileCertificateOutline,
	FileChartCheckOutline,
	FileChartOutline,
	FileDocumentCheckOutline,
	FileDocumentEditOutline,
	FileDocumentMultiple,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	FileExportOutline,
	FileRemoveOutline,
	FileSign,
	FileTreeOutline,
	FileXmlBox,
	FilterCogOutline,
	Finance,
	FlaskOutline,
	FolderLockOutline,
	FolderOpenOutline,
	FormatListChecks,
	FormatListNumbered,
	GaugeFull,
	Gavel,
	HandCoinOutline,
	HandHeartOutline,
	HelpRhombusOutline,
	History,
	KeyOutline,
	Leaf,
	LinkVariant,
	LockOutline,
	MapMarkerOutline,
	MapOutline,
	MessageTextOutline,
	Molecule,
	NotebookOutline,
	OfficeBuilding,
	OfficeBuildingOutline,
	PackageVariant,
	PackageVariantClosed,
	PercentOutline,
	PiggyBankOutline,
	PlusCircle,
	Poll,
	Pulse,
	ReceiptOutline,
	ReceiptTextOutline,
	RepeatVariant,
	Restore,
	RssBox,
	Scale,
	ScaleBalance,
	ShieldAlertOutline,
	ShieldCheckOutline,
	ShieldCrownOutline,
	ShieldHalfFull,
	ShieldPlusOutline,
	ShieldRemoveOutline,
	ShieldSearch,
	ShoppingOutline,
	SignatureFreehand,
	Sitemap,
	SourceBranch,
	SpeedometerSlow,
	StairsUp,
	StoreOutline,
	SwapHorizontal,
	SwapHorizontalBold,
	Sync,
	TableAccount,
	TableArrowRight,
	TableLarge,
	TableMergeCells,
	TagCheckOutline,
	TagOutline,
	TransferRight,
	TransitConnectionVariant,
	Truck,
	TruckOutline,
	VectorDifference,
	VectorIntersection,
	ViewDashboardOutline,
	Wallet,
	WalletOutline,
	Warehouse,
	WaterCheckOutline,
	WaterOutline,

	// ALIASES — names referenced by manifest fragments that do not exist in
	// vue-material-design-icons, mapped to the closest real icon.
	BankCheckOutline: BankCheck,
	BankTransferOutline: BankTransfer,
	BarcodeOutline: Barcode,
	CalculatorOutline: Calculator,
	CartArrowDownOutline: CartArrowDown,
	ChartBarOutline: ChartBar,
	ChartLineVariantOutline: ChartLineVariant,
	ChartTimelineVariantOutline: ChartTimelineVariant,
	ClipboardQuestionOutline: ClipboardTextSearchOutline,
	EmailClockOutline: EmailOutline,
	FileSignOutline: FileSign,
	FileXmlBoxOutline: FileXmlBox,
	FinanceOutline: Finance,
	GavelOutline: Gavel,
	HistoryIcon: History,
	LeafOutline: Leaf,
	LedgerOutline: NotebookOutline,
	PackageVariantOutline: PackageVariant,
	PollOutline: Poll,
	PulseOutline: Pulse,
	RssBoxOutline: RssBox,
	ScaleBalanceOutline: ScaleBalance,
	SwapHorizontalBoldOutline: SwapHorizontalBold,
	TableAccountOutline: TableAccount,
	TableMergeCellsOutline: TableMergeCells,
	WarehouseOutline: Warehouse,
}
